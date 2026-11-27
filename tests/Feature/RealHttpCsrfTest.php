<?php

/** testing専用の通常HTTPと専用Cookie・fileセッションで実際のCSRF拒否と再入力を検証する。 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RealHttpCsrfTest extends TestCase
{
    /** 独立HTTP接続の確定保存を使うため、作成対象をfinallyで限定削除する。 */
    public function test_real_csrf_tokens_reject_writes_without_retaining_input_and_allow_reentry_after_login(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('testing', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $baseline = $this->snapshot();
        $suffix = bin2hex(random_bytes(8));
        $email = 'http-csrf-'.$suffix.'@example.com';
        $genreName = 'HTTP検証'.$suffix;
        $storage = sys_get_temp_dir().'/bookshelf-http-'.$suffix;
        $process = null;
        try {
            foreach (['framework/sessions', 'framework/views', 'framework/cache/data', 'logs'] as $directory) {
                File::makeDirectory($storage.'/'.$directory, 0755, true);
            }
            $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            $this->assertNotFalse($socket, $errorMessage);
            $address = stream_socket_get_name($socket, false);
            fclose($socket);
            $process = new Process([PHP_BINARY, '-S', $address, base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php')], public_path(), [
                'APP_ENV' => 'testing', 'APP_RUNNING_IN_CONSOLE' => 'false', 'DB_DATABASE' => 'testing',
                'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'csrf_'.$suffix, 'LARAVEL_STORAGE_PATH' => $storage,
                'LOG_CHANNEL' => 'daily', 'APP_URL' => 'http://'.$address,
            ]);
            $process->setTimeout(90);
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'Development Server')), $process->getErrorOutput());
            $client = new Client(['base_uri' => 'http://'.$address, 'cookies' => new CookieJar, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 10]);
            $registration = ['name' => 'HTTP登録者', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password'];
            foreach (['_token' => 'wrong-token', 'missing' => null] as $case => $token) {
                $before = $this->snapshot();
                $response = $this->request($client, 'POST', '/register', $registration + ($token ? ['_token' => $token] : []));
                $this->assertExpired($response, $email);
                $this->assertNoRetainedInput($storage, $email);
                $this->assertSame($before, $this->snapshot(), $case);
            }
            $token = $this->token($this->request($client, 'GET', '/register'));
            $this->assertSame(302, $this->request($client, 'POST', '/register', $registration + ['_token' => $token])->getStatusCode());
            $user = User::where('email', $email)->firstOrFail();
            // 登録済みCookieとは分け、正しい資格情報でもCSRFを通るまでは未認証を維持する。
            $loginClient = new Client(['base_uri' => 'http://'.$address, 'cookies' => new CookieJar, 'allow_redirects' => false, 'http_errors' => false, 'timeout' => 10]);
            $this->token($this->request($loginClient, 'GET', '/login'));
            $credentials = ['email' => $email, 'password' => 'password'];
            foreach (['invalid', 'missing'] as $case) {
                $before = $this->snapshot();
                $body = $credentials + ($case === 'invalid' ? ['_token' => 'invalid-'.$suffix] : []);
                $this->assertExpired($this->request($loginClient, 'POST', '/login', $body), $email);
                $this->assertNoRetainedInput($storage, $email);
                $this->assertNoRetainedInput($storage, $credentials['password']);
                $this->assertSame($before, $this->snapshot(), 'POST /login '.$case);
                $protected = $this->request($loginClient, 'GET', '/books/create');
                $this->assertSame(302, $protected->getStatusCode());
                $this->assertStringEndsWith('/login', $protected->getHeaderLine('Location'));
            }
            $loginToken = $this->token($this->request($loginClient, 'GET', '/login'));
            $this->assertSame(302, $this->request($loginClient, 'POST', '/login', $credentials + ['_token' => $loginToken])->getStatusCode());
            $this->assertSame(200, $this->request($loginClient, 'GET', '/books/create')->getStatusCode());
            $token = $this->token($this->request($client, 'GET', '/genres/create'));
            $this->assertSame(302, $this->request($client, 'POST', '/genres', ['name' => $genreName, '_token' => $token])->getStatusCode());
            $genre = Genre::where('name', $genreName)->firstOrFail();
            $input = ['title' => 'HTTP作成書籍', 'author' => 'HTTP著者', 'isbn' => '077'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT), 'published_date' => '2026-01-02', 'genres' => [$genre->id]];
            $token = $this->token($this->request($client, 'GET', '/books/create'));
            $this->assertSame(302, $this->request($client, 'POST', '/books', $input + ['_token' => $token])->getStatusCode());
            $book = Book::where('isbn', $input['isbn'])->firstOrFail();
            $token = $this->token($this->request($client, 'GET', '/books/'.$book->id));
            $this->assertSame(302, $this->request($client, 'POST', '/books/'.$book->id.'/reviews', ['rating' => 4, 'comment' => 'HTTPレビュー', '_token' => $token])->getStatusCode());
            $review = $book->reviews()->firstOrFail();
            foreach (['/books/'.$book->id.'/favorites', '/reviews/'.$review->id.'/like'] as $url) {
                $this->assertSame(302, $this->request($client, 'POST', $url, ['_token' => $token])->getStatusCode());
            }
            $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'book_id' => $book->id]);
            $this->assertDatabaseHas('review_likes', ['user_id' => $user->id, 'review_id' => $review->id]);
            $marker = '送信しても保持しない'.$suffix;
            $entries = [['POST', '/books'], ['PUT', '/books/'.$book->id], ['DELETE', '/books/'.$book->id], ['POST', '/genres'], ['PUT', '/genres/'.$genre->id], ['DELETE', '/genres/'.$genre->id], ['POST', '/books/'.$book->id.'/reviews'], ['PUT', '/reviews/'.$review->id], ['DELETE', '/reviews/'.$review->id], ['POST', '/books/'.$book->id.'/favorites'], ['POST', '/reviews/'.$review->id.'/like'], ['POST', '/logout']];
            foreach ($entries as [$method, $url]) {
                foreach (['invalid', 'missing'] as $case) {
                    $before = $this->snapshot();
                    $body = array_replace($input, ['title' => $marker, 'comment' => $marker, 'name' => $marker, 'rating' => 5]);
                    if ($case === 'invalid') {
                        $body['_token'] = 'invalid-'.$suffix;
                    }
                    $response = $this->request($client, $method, $url, $body);
                    $this->assertExpired($response, $marker);
                    $this->assertNoRetainedInput($storage, $marker);
                    $this->assertSame($before, $this->snapshot(), $method.' '.$url.' '.$case);
                }
            }
            foreach (['/books/create', '/books/'.$book->id.'/edit', '/books/'.$book->id, '/genres/create', '/genres/'.$genre->id.'/edit', '/reviews/'.$review->id.'/edit'] as $url) {
                $form = $this->request($client, 'GET', $url);
                $this->assertSame(200, $form->getStatusCode());
                $this->assertStringNotContainsString($marker, (string) $form->getBody());
            }
            foreach (File::files($storage.'/framework/sessions') as $session) {
                $this->assertStringNotContainsString('_old_input', File::get($session));
            }
            // 正常PUTも実際に保存でき、メソッド自体を常時拒否していないことを確認する。
            $input['title'] = 'HTTP更新書籍';
            $this->assertSame(302, $this->request($client, 'PUT', '/books/'.$book->id, $input + ['_token' => $token])->getStatusCode());
            $this->assertDatabaseHas('books', ['id' => $book->id, 'title' => $input['title']]);
            $this->assertSame(302, $this->request($client, 'PUT', '/reviews/'.$review->id, ['rating' => 5, 'comment' => 'HTTP更新レビュー', '_token' => $token])->getStatusCode());
            $this->assertDatabaseHas('reviews', ['id' => $review->id, 'rating' => 5, 'comment' => 'HTTP更新レビュー']);
            $this->assertSame(302, $this->request($client, 'PUT', '/genres/'.$genre->id, ['name' => $genreName, '_token' => $token])->getStatusCode());
            // 専用セッションだけを失効させ、古いCSRF・ログイン誘導・再ログインを順に確認する。
            File::cleanDirectory($storage.'/framework/sessions');
            $before = $this->snapshot();
            $this->assertExpired($this->request($client, 'POST', '/books', $input + ['_token' => $token]), $input['title']);
            $this->assertNoRetainedInput($storage, $input['title']);
            $this->assertSame($before, $this->snapshot());
            $this->assertStringEndsWith('/login', $this->request($client, 'GET', '/books/create')->getHeaderLine('Location'));
            $token = $this->token($this->request($client, 'GET', '/login'));
            $this->assertSame(302, $this->request($client, 'POST', '/login', ['email' => $email, 'password' => 'password', '_token' => $token])->getStatusCode());
            $token = $this->token($this->request($client, 'GET', '/books/create'));
            $input['isbn'] = '078'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT);
            $input['title'] = '再入力して登録した書籍';
            $this->assertSame(302, $this->request($client, 'POST', '/books', $input + ['_token' => $token])->getStatusCode());
            $this->assertDatabaseHas('books', ['isbn' => $input['isbn'], 'title' => $input['title'], 'user_id' => $user->id]);
            $reentered = Book::where('isbn', $input['isbn'])->firstOrFail();
            $this->assertSame(302, $this->request($client, 'DELETE', '/reviews/'.$review->id, ['_token' => $token])->getStatusCode());
            $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
            foreach ([$book, $reentered] as $createdBook) {
                $this->assertSame(302, $this->request($client, 'DELETE', '/books/'.$createdBook->id, ['_token' => $token])->getStatusCode());
                $this->assertDatabaseMissing('books', ['id' => $createdBook->id]);
            }
            $this->assertSame(302, $this->request($client, 'DELETE', '/genres/'.$genre->id, ['_token' => $token])->getStatusCode());
            $this->assertDatabaseMissing('genres', ['id' => $genre->id]);
            $logout = $this->request($client, 'POST', '/logout', ['_token' => $token]);
            $this->assertSame(302, $logout->getStatusCode());
            $this->assertStringEndsWith('/login', $logout->getHeaderLine('Location'));
            $this->assertStringEndsWith('/login', $this->request($client, 'GET', '/books/create')->getHeaderLine('Location'));
        } finally {
            $process?->stop(2);
            User::where('email', $email)->delete();
            Genre::where('name', $genreName)->delete();
            File::deleteDirectory($storage);
            $this->assertSame($baseline, $this->snapshot(), '通常HTTPの確定保存を全て後片付けする');
        }
    }

    /** 419応答直後、次のHTTPでフラッシュが消費される前の専用セッションを照合する。 */
    private function assertNoRetainedInput(string $storage, string $marker): void
    {
        foreach (File::files($storage.'/framework/sessions') as $session) {
            $contents = File::get($session);
            $this->assertStringNotContainsString('_old_input', $contents);
            $this->assertStringNotContainsString($marker, $contents);
            $decoded = json_decode($contents, true);
            if (is_array($decoded)) {
                $this->assertStringNotContainsString($marker, json_encode($decoded, JSON_UNESCAPED_UNICODE));
            }
        }
    }

    private function snapshot(): array
    {
        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function request(Client $client, string $method, string $path, array $input = []): ResponseInterface
    {
        return $client->request($method, $path, $method === 'GET' ? [] : ['form_params' => $input]);
    }

    /** 既存Bladeが通常HTTPで発行したトークンだけを正常系に使用する。 */
    private function token(ResponseInterface $response): string
    {
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, preg_match('/name="_token" value="([^"]+)"/', (string) $response->getBody(), $matches));

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    private function assertExpired(ResponseInterface $response, string $input): void
    {
        $this->assertSame(419, $response->getStatusCode());
        (new RegisterFacades)->bootstrap(app());
        (new RegisterErrorViewPaths)();
        $this->assertSame(view('errors::419')->render(), (string) $response->getBody());
        $this->assertStringNotContainsString($input, (string) $response->getBody());
    }
}

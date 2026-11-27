<?php

/** 共通ジャンル管理の認証・表示範囲・入力・保存競合をtesting DBで検証する。 */

namespace Tests\Feature;

use App\Http\Requests\GenreRequest;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithRenderedHtml;
use Tests\TestCase;

class GenreManagementTest extends TestCase
{
    use InteractsWithRenderedHtml;

    /** 既存データを消さず、各テストの変更をトランザクション内に限定する。 */
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::beginTransaction();
        $this->withoutVite();
    }

    /** モデルイベントとDBの変更を各テストで片付ける。 */
    protected function tearDown(): void
    {
        Genre::flushEventListeners();
        DB::rollBack();
        parent::tearDown();
    }

    /** T-5-1・6・12 / AC-5-02・03：別会員も編集・削除でき、成功フラッシュを表示する。 */
    public function test_t_5_1_6_12_members_share_create_update_delete(): void
    {
        $this->actingAs(User::factory()->create());
        $this->assertSame('', $this->createDomXPath($this->get(route('genres.create'))->assertOk())->query('//input[@name="name"]')->item(0)->getAttribute('value'));
        $this->post(route('genres.store'), ['name' => '  新規名  ', 'user_id' => 999])->assertRedirect(route('genres.index'))->assertSessionHas('success', 'ジャンルを登録しました。');
        $this->get(route('genres.index'))->assertSee('ジャンルを登録しました。');
        $genre = Genre::where('name', '新規名')->firstOrFail();
        $this->actingAs(User::factory()->create());
        $edit = $this->get(route('genres.edit', $genre))->assertOk();
        $this->assertSame('新規名', $this->createDomXPath($edit)->query('//input[@name="name"]')->item(0)->getAttribute('value'));
        $this->put(route('genres.update', $genre), ['name' => '新規名'])->assertRedirect(route('genres.index'));
        $this->put(route('genres.update', $genre), ['name' => ' 更新名 '])->assertRedirect(route('genres.index'))->assertSessionHas('success', 'ジャンルを更新しました。');
        $this->get(route('genres.index'))->assertSee('更新名')->assertSee('ジャンルを更新しました。');
        $this->delete(route('genres.destroy', $genre))->assertRedirect(route('genres.index'))->assertSessionHas('success', 'ジャンルを削除しました。');
        $this->get(route('genres.index'))->assertSee('ジャンルを削除しました。')->assertDontSee('更新名');
        $this->assertDatabaseMissing('genres', ['id' => $genre->id]);
    }

    /** T-5-6 / AC-5-02：別会員の名前更新でも書籍・全紐付け・無関係な保存値と表示集合を維持する。 */
    public function test_t_5_6_renaming_linked_genre_preserves_books_and_all_associations(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('genres.store'), ['name' => '紐付きを持つ更新前ジャンル'])
            ->assertRedirect(route('genres.index'));
        $genre = Genre::where('name', '紐付きを持つ更新前ジャンル')->firstOrFail();
        $otherGenre = Genre::factory()->create(['name' => '無関係なジャンル']);
        $booksByDay = [];
        foreach ([3, 1, 5] as $day) {
            $book = Book::factory()->create(['created_at' => '2026-10-0'.$day.' 12:00:00']);
            $book->genres()->attach($genre);
            $booksByDay[$day] = $book->id;
        }
        // 同じ書籍の別ジャンル、別書籍の紐付け、未紐付け書籍も更新の影響を受けない。
        $book->genres()->attach($otherGenre);
        Book::factory()->create()->genres()->attach($otherGenre);
        Book::factory()->create();
        krsort($booksByDay);
        $expectedIds = array_values($booksByDay);
        $this->actingAs(User::factory()->create());

        $assertDisplay = function (string $name) use ($genre, $expectedIds): void {
            $index = $this->get(route('genres.index'))->assertOk()->assertSee($name);
            $listedGenre = $index->viewData('genres')->firstWhere('id', $genre->id);
            $this->assertSame($name, $listedGenre->name);
            $this->assertSame(count($expectedIds), $listedGenre->books_count);
            $detail = $this->get(route('genres.show', $genre))->assertOk()->assertSee('ジャンル: '.$name);
            $this->assertSame($name, $detail->viewData('genre')->name);
            $this->assertSame($expectedIds, $detail->viewData('books')->getCollection()->modelKeys());
            $this->assertSame(count($expectedIds), $detail->viewData('books')->total());
        };
        $assertDisplay($genre->name);
        $beforeGenre = $genre->fresh()->getAttributes();
        $beforeOtherGenres = DB::table('genres')->where('id', '!=', $genre->id)->orderBy('id')->get()->toJson();
        $unchangedTables = ['users', 'books', 'book_genre', 'reviews', 'favorites', 'review_likes'];
        $before = [];
        foreach ($unchangedTables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        $this->put(route('genres.update', $genre), ['name' => '紐付きを持つ更新後ジャンル'])
            ->assertRedirect(route('genres.index'))->assertSessionHas('success', 'ジャンルを更新しました。');
        $this->get(route('genres.index'))->assertOk()->assertSee('ジャンルを更新しました。');
        $afterGenre = $genre->fresh()->getAttributes();
        $this->assertSame('紐付きを持つ更新後ジャンル', $afterGenre['name']);
        // 更新許可項目だけを外し、主キー・登録日時を含む残りの保存項目を厳密比較する。
        unset($beforeGenre['name'], $beforeGenre['updated_at'], $afterGenre['name'], $afterGenre['updated_at']);
        $this->assertSame($beforeGenre, $afterGenre);
        foreach ($unchangedTables as $table) {
            $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson(), $table.'の全保存項目を維持する');
        }
        $this->assertSame($beforeOtherGenres, DB::table('genres')->where('id', '!=', $genre->id)->orderBy('id')->get()->toJson());
        $assertDisplay('紐付きを持つ更新後ジャンル');
    }

    /** T-5-2：認証を形式検証・対象解決より優先し、会員の不正対象は404とする。 */
    public function test_t_5_2_authentication_precedes_identifier_and_target_validation(): void
    {
        $genre = Genre::factory()->create();
        $deleted = Genre::factory()->create();
        $deleted->delete();
        $ids = [$genre->id, '999999999', $deleted->id, $genre->id.'abc', $genre->id.'.0', '+'.$genre->id, '-'.$genre->id, $genre->id.'e0'];
        $requests = [['GET', '/genres'], ['GET', '/genres/create'], ['POST', '/genres']];
        foreach ($ids as $id) {
            foreach ([['GET', ''], ['GET', '/edit'], ['PUT', ''], ['DELETE', '']] as [$method, $suffix]) {
                $requests[] = [$method, '/genres/'.$id.$suffix];
            }
        }
        $before = $genre->fresh()->getAttributes();
        foreach ($requests as [$method, $path]) {
            $this->call($method, $path, ['name' => []])->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create());
        foreach (array_slice($requests, 7) as [$method, $path]) {
            $this->call($method, $path, ['name' => []])->assertNotFound();
        }
        $this->assertSame($before, $genre->fresh()->getAttributes());
        $this->assertDatabaseMissing('genres', ['id' => $deleted->id]);
    }

    /** T-5-3・5 / AC-5-01：一覧の全件ID順・件数・各管理導線を照合する。 */
    public function test_t_5_3_5_index_lists_all_genres_in_id_order_with_counts_and_links(): void
    {
        $this->actingAs(User::factory()->create());
        $baseline = Genre::withCount('books')->orderBy('id')->get();
        if ($baseline->isEmpty()) {
            $this->get(route('genres.index'))->assertOk()->assertSee('ジャンルが登録されていません。');
        }
        $genres = collect(range(1, 12))->map(fn ($i) => Genre::factory()->create(['name' => '名前'.(20 - $i)]));
        $book = Book::factory()->create();
        $book->genres()->attach($genres[1]);
        $response = $this->get(route('genres.index'))->assertOk();
        $allGenres = $baseline->concat($genres);
        $this->assertSame($allGenres->pluck('id')->all(), $response->viewData('genres')->modelKeys());
        $xpath = $this->createDomXPath($response);
        $this->assertSame($allGenres->count(), $xpath->query('//tbody/tr')->length);
        foreach ($allGenres as $i => $genre) {
            $row = $xpath->query('//tbody/tr')->item($i);
            $this->assertStringContainsString($genre->name, $row->textContent);
            $this->assertSame((string) ($genre->id === $genres[1]->id ? 1 : ($genre->books_count ?? 0)).'冊', trim($xpath->query('./td', $row)->item(1)->textContent));
            foreach (['show', 'edit'] as $action) {
                $this->assertSame(1, $xpath->query('.//a[@href="'.route('genres.'.$action, $genre).'"]', $row)->length);
            }
            $this->assertSame(1, $xpath->query('.//form[@action="'.route('genres.destroy', $genre).'"]', $row)->length);
        }
        $this->assertSame(1, $xpath->query('//a[@href="'.route('genres.create').'"]')->length);
    }

    /** 詳細のページ境界データを返す。 */
    public static function bookCounts(): array
    {
        return [[0], [1], [10], [11]];
    }

    /** T-5-4・5 / AC-5-01：除外書籍を混ぜ、日時順・全ページの厳密な対象IDを確認する。 */
    #[DataProvider('bookCounts')]
    public function test_t_5_4_5_detail_filters_before_paginating_and_renders_links(int $count): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create();
        $other = Genre::factory()->create();
        $idsByDay = [];
        foreach ([4, 11, 2, 8, 1, 10, 6, 3, 9, 5, 7] as $index => $day) {
            Book::factory()->create(['created_at' => '2026-10-'.sprintf('%02d', $day).' 12:00:00'])->genres()->attach($other);
            if ($index < $count) {
                $book = Book::factory()->create(['created_at' => '2026-10-'.sprintf('%02d', $day).' 12:00:00']);
                $book->genres()->attach([$genre->id, $other->id]);
                $idsByDay[$day] = $book->id;
            }
        }
        krsort($idsByDay);
        $expected = array_values($idsByDay);
        foreach (range(1, max(1, (int) ceil($count / 10))) as $page) {
            $response = $this->get(route('genres.show', $genre).'?page='.$page)->assertOk();
            $books = $response->viewData('books');
            $this->assertSame($count, $books->total());
            $pageIds = array_slice($expected, ($page - 1) * 10, 10);
            $this->assertSame($pageIds, $books->getCollection()->modelKeys());
            $xpath = $this->createDomXPath($response);
            $links = $xpath->query('//a[contains(@class,"block border rounded-lg")]');
            $this->assertSame(count($pageIds), $links->length);
            foreach ($pageIds as $i => $id) {
                $this->assertSame(route('books.show', $id), $links->item($i)->getAttribute('href'));
                $this->assertTrue($books[$i]->relationLoaded('genres'));
                $this->assertSame($genre->name, trim($xpath->query('.//span[contains(@class,"bg-blue-200")]', $links->item($i))->item(0)->textContent));
            }
            $response->assertSee(route('books.index'), false);
            if ($count === 0) {
                $response->assertSee('このジャンルの書籍はまだ登録されていません。');
            }
        }
    }

    /** 各フォームに共通する不正入力と暫定文言を返す。 */
    public static function invalidNames(): array
    {
        return [[null, 'ジャンル名は必須です。'], ['', 'ジャンル名は必須です。'], ['   ', 'ジャンル名は必須です。'], [[], 'ジャンル名は必須です。'], [['不正'], 'ジャンル名は文字列で入力してください。'], [123, 'ジャンル名は文字列で入力してください。'], [str_repeat('あ', 256), 'ジャンル名は255文字以内で入力してください。'], [' dUpLiCaTe ', 'そのジャンル名は既に使用されています。']];
    }

    /** T-5-7〜9・13：POST/PUTの失敗後DOMで旧入力・欄下エラーと保存値不変を確認する。 */
    #[DataProvider('invalidNames')]
    public function test_t_5_7_8_9_13_invalid_names_return_to_internal_form_without_changing_data(mixed $name, string $message): void
    {
        $this->actingAs(User::factory()->create());
        Genre::factory()->create(['name' => 'Duplicate']);
        $genre = Genre::factory()->create(['name' => '元の名前']);
        foreach (['POST', 'PUT'] as $method) {
            $before = DB::table('genres')->orderBy('id')->get()->toJson();
            $url = $method === 'POST' ? route('genres.store') : route('genres.update', $genre);
            $form = $method === 'POST' ? route('genres.create') : route('genres.edit', $genre);
            $this->from('https://outside.example/')->call($method, $url, ['name' => $name])->assertRedirect($form);
            $response = $this->get($form)->assertOk();
            $xpath = $this->createDomXPath($response);
            $this->assertSame(is_string($name) ? trim($name) : '', $xpath->query('//input[@name="name"]')->item(0)->getAttribute('value'));
            $this->assertSame($message, trim($xpath->query('//input[@name="name"]/following-sibling::p')->item(0)->textContent));
            $this->assertSame($before, DB::table('genres')->orderBy('id')->get()->toJson());
        }
    }

    /** T-5-8・9：255文字・全半角区別・大小同名更新がDB制約と整合する。 */
    public function test_t_5_8_9_length_width_and_case_rules_match_database(): void
    {
        $this->actingAs(User::factory()->create());
        foreach ([str_repeat('あ', 255), 'ABC', 'ＡＢＣ', 'かたかな', 'カタカナ'] as $name) {
            $this->post(route('genres.store'), ['name' => $name])->assertRedirect(route('genres.index'));
            $this->assertDatabaseHas('genres', ['name' => $name]);
        }
        $genre = Genre::where('name', 'ABC')->firstOrFail();
        $this->put(route('genres.update', $genre), ['name' => ' abc '])->assertRedirect(route('genres.index'));
        $this->assertSame('abc', $genre->fresh()->name);
        $long = str_repeat('い', 255);
        $this->put(route('genres.update', $genre), ['name' => $long])->assertRedirect(route('genres.index'));
        $this->assertSame($long, $genre->fresh()->name);
        Genre::create(['name' => 'abc']);
        try {
            DB::table('genres')->insert(['name' => 'ABC']);
            $this->fail('DB一意制約が必要');
        } catch (QueryException $exception) {
            $this->assertSame(1062, $exception->errorInfo[1]);
        }
    }

    /** T-5-10 / AC-5-04：紐付き削除拒否で全保存項目と関連を維持する。 */
    public function test_t_5_10_linked_genre_cannot_be_deleted(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create();
        $book = $this->createDeletionPreservationData();
        $book->genres()->attach($genre);
        $before = $this->businessSnapshot();
        $this->delete(route('genres.destroy', $genre))->assertRedirect(route('genres.index'))->assertSessionHas('error', 'このジャンルには書籍が紐付いているため削除できません。');
        $this->get(route('genres.index'))->assertSee('このジャンルには書籍が紐付いているため削除できません。');
        $this->assertSame($before, $this->businessSnapshot());
    }

    /** T-5-11：事前確認後の挿入をイベントで模擬し、実RESTRICTと拒否応答を確認する。 */
    public function test_t_5_11_restrict_handles_link_added_after_precheck(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create();
        $book = $this->createDeletionPreservationData();
        $before = $this->businessSnapshot();
        $linkAdded = false;
        Genre::deleting(function (Genre $target) use ($book, &$before, &$linkAdded) {
            $book->genres()->attach($target);
            // 既知の前提変更である追加1行だけを加え、拒否処理の先行副作用を期待値へ混ぜない。
            $before['book_genre'][] = (array) DB::table('book_genre')->where('book_id', $book->id)->where('genre_id', $target->id)->first();
            usort($before['book_genre'], fn ($left, $right) => $left['id'] <=> $right['id']);
            $linkAdded = true;
        });
        $this->delete(route('genres.destroy', $genre))->assertRedirect(route('genres.index'))->assertSessionHas('error', 'このジャンルには書籍が紐付いているため削除できません。');
        $this->assertTrue($linkAdded, '事前確認後に紐付けが追加されたこと');
        $this->assertSame($before, $this->businessSnapshot());
        $this->assertDatabaseHas('book_genre', ['book_id' => $book->id, 'genre_id' => $genre->id]);
    }

    /** 削除対象側と無関係側の全関連に実データを置き、誤った連鎖削除も検出する。 */
    private function createDeletionPreservationData(): Book
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $otherGenre = Genre::factory()->create();
        $books = [];
        foreach ([$owner, $other] as $index => $user) {
            $book = Book::factory()->create(['user_id' => $user->id]);
            $book->genres()->attach($otherGenre);
            $review = Review::factory()->create(['user_id' => $user->id, 'book_id' => $book->id, 'rating' => $index + 3, 'comment' => '削除拒否で維持するレビュー'.$index]);
            $other->favoriteBooks()->attach($book);
            $owner->likedReviews()->attach($review);
            $books[] = $book;
        }

        return $books[0];
    }

    /** 拒否前後で日時・関連IDを含む業務7表の全保存値をDBから取り直す。 */
    private function businessSnapshot(): array
    {
        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }

    /** T-5-11：独立プロセスの削除が挿入トランザクションを待つことを観測し、実並行RESTRICTを確認する。 */
    public function test_t_5_11_independent_connection_restricts_concurrent_delete(): void
    {
        config(['database.connections.genre_race' => config('database.connections.mysql')]);
        $connection = DB::connection('genre_race');
        $this->assertSame('testing', $connection->getDatabaseName());
        $user = User::factory()->make();
        $user->setConnection('genre_race')->save();
        $genre = new Genre(['name' => '並行検証'.bin2hex(random_bytes(8))]);
        $genre->setConnection('genre_race')->save();
        $book = Book::factory()->make(['user_id' => $user->id]);
        $book->setConnection('genre_race')->save();
        $input = new InputStream;
        $script = <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$connection = Illuminate\Support\Facades\DB::connection();
if ($connection->getDatabaseName() !== 'testing') { exit(2); }
$connection->statement('SET SESSION innodb_lock_wait_timeout = 5');
$genre = App\Models\Genre::findOrFail($argv[1]);
if ($genre->books()->exists()) { exit(3); }
echo "ready\n";
fflush(STDOUT);
if (trim(fgets(STDIN)) !== 'delete') { exit(4); }
$response = (new App\Http\Controllers\GenreController)->destroy($genre);
echo json_encode(['url' => $response->getTargetUrl(), 'error' => session('error')], JSON_UNESCAPED_UNICODE)."\n";
CHILD;
        $process = new Process([PHP_BINARY, '-r', $script, (string) $genre->id], base_path(), ['APP_ENV' => 'testing'], $input, 10);
        try {
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, "ready\n")));
            $connection->beginTransaction();
            $connection->table('book_genre')->insert(['book_id' => $book->id, 'genre_id' => $genre->id]);
            $input->write("delete\n");
            $deadline = microtime(true) + 4;
            $waiting = false;
            do {
                foreach ($connection->select('SHOW PROCESSLIST') as $thread) {
                    if (str_contains(strtolower($thread->Info ?? ''), 'delete from `genres`')) {
                        $waiting = true;
                        break;
                    }
                }
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && microtime(true) < $deadline && $process->isRunning());
            $this->assertTrue($waiting, '独立接続で削除SQLが実行中であることを観測する');
            $connection->commit();
            $input->close();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $lines = explode("\n", trim($process->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(route('genres.index'), $result['url']);
            $this->assertSame('このジャンルには書籍が紐付いているため削除できません。', $result['error']);
            $this->assertTrue($connection->table('genres')->where('id', $genre->id)->exists());
            $this->assertTrue($connection->table('books')->where('id', $book->id)->exists());
            $this->assertTrue($connection->table('book_genre')->where('genre_id', $genre->id)->where('book_id', $book->id)->exists());
        } finally {
            if ($connection->transactionLevel()) {
                $connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(0);
            }
            $input->close();
            $connection->table('users')->where('id', $user->id)->delete();
            $connection->table('genres')->where('id', $genre->id)->delete();
            DB::purge('genre_race');
        }
    }

    /** T-5-9：検証後の重複挿入を模擬し、登録・更新の実一意制約を入力エラーへ戻す。 */
    public function test_t_5_9_duplicate_after_validation_returns_normal_field_error(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create(['name' => '元の名前']);
        foreach (['POST', 'PUT'] as $method) {
            $name = '競合'.$method;
            Genre::saving(function (Genre $target) use ($name) {
                if ($target->name === $name) {
                    DB::table('genres')->insert(['name' => $name]);
                }
            });
            $before = $genre->fresh()->getAttributes();
            $form = $method === 'POST' ? route('genres.create') : route('genres.edit', $genre);
            $this->call($method, $method === 'POST' ? route('genres.store') : route('genres.update', $genre), ['name' => $name])->assertRedirect($form);
            $xpath = $this->createDomXPath($this->get($form)->assertOk());
            $this->assertSame($name, $xpath->query('//input[@name="name"]')->item(0)->getAttribute('value'));
            $this->assertSame('そのジャンル名は既に使用されています。', trim($xpath->query('//input[@name="name"]/following-sibling::p')->item(0)->textContent));
            $this->assertSame($before, $genre->fresh()->getAttributes());
            Genre::flushEventListeners();
        }
    }

    /** T-5-2：入力検証直後に消えた対象は保存直前の確認で404となる。 */
    public function test_t_5_2_target_deleted_after_validation_is_not_recreated(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create();
        $this->app->afterResolving(GenreRequest::class, function () use ($genre) {
            DB::table('genres')->where('id', $genre->id)->delete();
        });
        $this->put(route('genres.update', $genre), ['name' => '新しい名'])->assertNotFound();
        $this->assertDatabaseMissing('genres', ['id' => $genre->id]);
        $this->assertDatabaseMissing('genres', ['name' => '新しい名']);
    }

    /** T-5-9・11：別の制約・接続障害は通常エラーへ変換せず再送出する。 */
    public function test_t_5_9_11_unrelated_database_failures_are_rethrown(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create();
        $before = $genre->fresh()->getAttributes();
        $this->withoutExceptionHandling();
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            foreach ([[1062, "Duplicate entry for key 'other_unique'"], [1451, 'other_foreign'], [2006, 'server gone away']] as [$code, $detail]) {
                $exception = new QueryException('mysql', 'test', [], new \PDOException($detail));
                $exception->errorInfo = ['HY000', $code, $detail];
                $event = $method === 'DELETE' ? 'deleting' : 'saving';
                Genre::$event(function () use ($exception) {
                    throw $exception;
                });
                try {
                    $this->call($method, $method === 'POST' ? route('genres.store') : route('genres.update', $genre), ['name' => '変更名']);
                    $this->fail('想定外障害の再送出が必要');
                } catch (QueryException $actual) {
                    $this->assertSame($exception, $actual);
                } finally {
                    Genre::flushEventListeners();
                }
                $this->assertSame($before, $genre->fresh()->getAttributes());
            }
        }
    }
}

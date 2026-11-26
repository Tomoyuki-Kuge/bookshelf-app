<?php

/** お気に入りの認証・表示範囲・戻り先・原子保存と実並行処理を検証する。 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithRenderedHtml;
use Tests\Concerns\ObservesConcurrentSql;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use InteractsWithRenderedHtml;
    use ObservesConcurrentSql;

    /** testingだけを使い通常ケースを取り消せるようにする。 */
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::beginTransaction();
        $this->withoutVite();
    }

    /** 通常ケースの変更を戻す。 */
    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /** 全業務表の全保存値を主キー順に比較する。 */
    private function snapshot($connection = null): array
    {
        $connection ??= DB::connection();

        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])
            ->mapWithKeys(fn ($table) => [$table => $connection->table($table)->orderBy('id')->get()->toJson()])->all();
    }

    /** 操作で変化してよい組だけを全保存値比較から除外する。 */
    private function withoutPairs(array $snapshot, array $pairs): array
    {
        $snapshot['favorites'] = collect(json_decode($snapshot['favorites']))
            ->reject(fn ($row) => in_array([$row->user_id, $row->book_id], $pairs, true))->values()->toJson();

        return $snapshot;
    }

    /** AC-9-01〜02 / T-9-1・2・7：本人・他者書籍、送信ID無視、日時と対象外不変。 */
    public function test_toggle_uses_only_authenticated_user_and_route(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $books = [Book::factory()->for($user)->create(), Book::factory()->for($other)->create()];
        $review = Review::factory()->for($books[0])->create();
        $other->likedReviews()->attach($review);
        $other->favoriteBooks()->attach([$books[0]->id, $books[1]->id]);
        $books[0]->genres()->attach(Genre::factory()->create());
        $this->travelTo(now()->setDate(2030, 1, 2)->setTime(3, 4, 5));
        foreach ($books as $book) {
            $pairs = [[$user->id, $book->id]];
            $before = $this->withoutPairs($this->snapshot(), $pairs);
            foreach ([true, false, true, false] as $adding) {
                $user->setRelation('favoriteBooks', $adding ? collect([$book]) : collect());
                $this->actingAs($user)->from(route('books.show', $book))->post(route('favorites.toggle', $book), ['user_id' => $other->id, 'book_id' => $books[0]->id])
                    ->assertRedirect(route('books.show', $book))->assertSessionMissing('success')->assertSessionMissing('status')->assertSessionHasNoErrors();
                $saved = DB::table('favorites')->where('user_id', $user->id)->where('book_id', $book->id)->get();
                $this->assertCount($adding ? 1 : 0, $saved);
                if ($adding) {
                    $row = (array) $saved->first();
                    $this->assertSame(['id' => $row['id'], 'user_id' => $user->id, 'book_id' => $book->id, 'created_at' => '2030-01-02 03:04:05', 'updated_at' => '2030-01-02 03:04:05'], $row);
                }
                $this->assertSame($before, $this->withoutPairs($this->snapshot(), $pairs));
                $response = $this->actingAs($user->fresh())->get(route('books.show', $book))->assertOk();
                $xpath = $this->createDomXPath($response);
                $this->assertCount(1, $xpath->query('//form[@action="'.route('favorites.toggle', $book).'"]/button[@title="'.($adding ? 'お気に入りから削除' : 'お気に入りに追加').'"]'));
                $this->get(route('favorites.index'))->assertViewHas('books', fn ($page) => $page->pluck('id')->all() === ($adding ? [$book->id] : []));
            }
        }
        $this->travelBack();
    }

    /** AC-9-04 / T-9-3〜5：除外後ページ分割、書籍日時と逆順、0・1・10・11件と画面項目。 */
    public function test_index_filters_before_pagination_and_uses_favorite_dates(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $response = $this->actingAs($user)->get(route('favorites.index'))->assertOk()->assertSee('お気に入りに登録された書籍はありません。');
        $links = $this->createDomXPath($response)->query('//main//a[normalize-space(.)="書籍一覧を見る"]');
        $this->assertCount(1, $links);
        $this->assertSame(route('books.index'), $links[0]->getAttribute('href'));
        // 追加日時は書籍・中間行IDの昇降順や書籍登録日時と一致させない。
        $favoriteMinutes = [1 => 3, 3 => 9, 4 => 1, 5 => 7, 6 => 5, 8 => 11, 9 => 2, 10 => 8, 11 => 4, 13 => 10, 14 => 6];
        $expectedOrder = [8, 13, 3, 10, 5, 14, 6, 11, 1, 9, 4];
        $booksByIndex = [];
        foreach (range(1, 14) as $index) {
            $book = Book::factory()->for($other)->create(['created_at' => sprintf('2029-01-%02d 00:00:00', 15 - $index), 'image_url' => $index === 1 ? 'https://example.com/cover.png' : null]);
            // 他利用者だけの登録分も先頭・途中・境界付近の日時に置く。
            $minute = $favoriteMinutes[$index] ?? [2 => 12, 7 => 8, 12 => 2][$index];
            $other->favoriteBooks()->attach($book, ['created_at' => sprintf('2030-01-01 00:%02d:30', $minute)]);
            if (! array_key_exists($index, $favoriteMinutes)) {
                continue;
            }
            $booksByIndex[$index] = $book;
            $user->favoriteBooks()->attach($book, ['created_at' => sprintf('2030-01-01 00:%02d:00', $minute)]);
            $count = count($booksByIndex);
            if (in_array($count, [1, 10, 11])) {
                $ids = array_values(array_map(fn ($index) => $booksByIndex[$index]->id, array_filter($expectedOrder, fn ($index) => isset($booksByIndex[$index]))));
                $this->get(route('favorites.index'))->assertOk()->assertViewHas('books', fn ($page) => $page->total() === $count && $page->count() === min(10, $count) && $page->pluck('id')->all() === array_slice($ids, 0, 10));
            }
        }
        $ids = array_map(fn ($index) => $booksByIndex[$index]->id, $expectedOrder);
        foreach ([1, 2] as $page) {
            $response = $this->get(route('favorites.index', ['page' => $page]))->assertOk()->assertViewHas('books', fn ($books) => $books->total() === 11 && $books->count() === ($page === 1 ? 10 : 1) && $books->pluck('id')->all() === array_slice($ids, ($page - 1) * 10, 10));
            foreach (array_slice($ids, ($page - 1) * 10, 10) as $id) {
                $book = Book::findOrFail($id);
                $response->assertSee($book->title)->assertSee($book->author)->assertSee($book->isbn)->assertSee(route('books.show', $book))->assertSee(route('favorites.toggle', $book));
                if ($book->image_url) {
                    $response->assertSee($book->image_url);
                }
            }
            $response->assertSee('No Image');
        }
    }

    /** 戻り先の入力境界を独立に確認する。 */
    public static function sources(): array
    {
        return [
            'missing' => [null, false, 1], 'broken' => ['http://[', false, 1],
            'external' => ['https://evil.example/favorites?page=2', false, 1],
            'scheme' => ['https://localhost/favorites?page=2', false, 1],
            'port' => ['http://localhost:81/favorites?page=2', false, 1],
            'similar-path' => ['http://localhost/favorites/extra?page=2', false, 1],
            'relative' => ['/favorites?page=2', false, 1],
            'default-port' => ['http://localhost:80/favorites?page=2&unused=secret#fragment', true, 2],
            'page' => ['http://localhost/favorites?page=2', true, 2],
            'omitted' => ['http://localhost/favorites', true, 1],
            'array' => ['http://localhost/favorites?page[]=2', true, 1],
            'duplicate' => ['http://localhost/favorites?page=1&page=2', true, 1],
            'zero' => ['http://localhost/favorites?page=0', true, 1],
            'negative' => ['http://localhost/favorites?page=-1', true, 1],
            'fraction' => ['http://localhost/favorites?page=1.5', true, 1],
            'overflow' => ['http://localhost/favorites?page=999999999999999999999', true, 1],
            'out-of-range' => ['http://localhost/favorites?page=999', true, 2],
        ];
    }

    /** T-9-2・8：安全な内部遷移とコミット後件数による補正・表示範囲。 */
    #[DataProvider('sources')]
    public function test_referer_and_page_are_validated(?string $referer, bool $index, int $page): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(12)->create();
        foreach ($books as $offset => $book) {
            $user->favoriteBooks()->attach($book, ['created_at' => now()->addMinutes($offset)]);
        }
        $target = $books->last();
        $headers = $referer === null ? [] : ['referer' => $referer];
        $response = $this->actingAs($user)->post(route('favorites.toggle', $target), [], $headers);
        $url = $index ? route('favorites.index', ['page' => $page]) : route('books.show', $target);
        $response->assertRedirect($url)->assertSessionMissing('success')->assertSessionMissing('status');
        $this->get($url)->assertOk();
        if ($index) {
            $expected = $books->take(11)->reverse()->pluck('id')->values()->slice(($page - 1) * 10, 10)->values()->all();
            $this->get($url)->assertViewHas('books', fn ($books) => $books->total() === 11 && $books->pluck('id')->all() === $expected);
        }
    }

    /** AC-9-03 / T-9-8：11→10の最終ページ消滅と1→0の空表示。 */
    public function test_removal_clamps_disappeared_page_and_empty_list(): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(11)->create();
        foreach ($books as $offset => $book) {
            $user->favoriteBooks()->attach($book, ['created_at' => now()->addMinutes($offset)]);
        }
        $this->actingAs($user)->from(route('favorites.index', ['page' => 2]))->post(route('favorites.toggle', $books->first()))->assertRedirect(route('favorites.index', ['page' => 1]))->assertSessionMissing('success');
        $this->get(route('favorites.index'))->assertViewHas('books', fn ($page) => $page->total() === 10 && $page->pluck('id')->all() === $books->skip(1)->reverse()->pluck('id')->values()->all());
        $user->favoriteBooks()->sync([$books->last()->id]);
        $this->from(route('favorites.index'))->post(route('favorites.toggle', $books->last()))->assertRedirect(route('favorites.index', ['page' => 1]))->assertSessionMissing('success');
        $this->get(route('favorites.index', ['page' => 1]))->assertSee('お気に入りに登録された書籍はありません。')->assertViewHas('books', fn ($page) => $page->total() === 0 && $page->currentPage() === 1);
    }

    /** T-9-6〜7：未認証・不存在・削除済みの拒否とCASCADEで対象外の全保存値を維持。 */
    public function test_guest_missing_and_deleted_books_preserve_saved_values(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();
        $other = Book::factory()->create();
        $user->favoriteBooks()->attach([$book->id, $other->id]);
        $before = $this->snapshot();
        $this->get(route('favorites.index'))->assertRedirect(route('login'));
        foreach ([$book->id, 999999999] as $id) {
            $this->post(route('favorites.toggle', $id))->assertRedirect(route('login'));
            $this->assertSame($before, $this->snapshot());
        }
        $this->actingAs($user)->post(route('favorites.toggle', 999999999))->assertNotFound();
        $this->assertSame($before, $this->snapshot());
        $this->delete(route('books.destroy', $book))->assertRedirect(route('books.index'));
        $expected = $before;
        $expected['books'] = collect(json_decode($before['books']))->reject(fn ($row) => $row->id === $book->id)->values()->toJson();
        $expected['favorites'] = collect(json_decode($before['favorites']))->reject(fn ($row) => $row->book_id === $book->id)->values()->toJson();
        $this->assertSame($expected, $this->snapshot());
        $this->post(route('favorites.toggle', $book))->assertNotFound();
        $this->assertSame($expected, $this->snapshot());
    }

    /** 追加と解除の書き込み障害を検証する。 */
    public static function initialStates(): array
    {
        return [[false], [true]];
    }

    /** T-9-9：書き込み後障害を取り消し、安全な500とログへ渡す。 */
    #[DataProvider('initialStates')]
    public function test_failure_after_write_rolls_back_every_saved_value(bool $initial): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->create();
        $review = Review::factory()->create();
        $other->favoriteBooks()->attach([$book->id, $review->book_id]);
        $other->likedReviews()->attach($review);
        $review->book->genres()->attach(Genre::factory()->create());
        if ($initial) {
            $user->favoriteBooks()->attach($book);
        }
        $before = $this->snapshot();
        $logger = Log::spy();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && preg_match('/^(insert into|delete from) `favorites`/i', $query->sql)) {
                $armed = false;
                throw new QueryException('mysql', 'SQL-secret', ['password-secret'], new \PDOException('unexpected'));
            }
        });
        $this->actingAs($user)->post(route('favorites.toggle', $book))->assertStatus(500)->assertDontSee('SQL-secret')->assertDontSee('password-secret')->assertSessionMissing('success');
        $this->assertFalse($armed);
        $this->assertSame($before, $this->snapshot());
        $logger->shouldHaveReceived('error')->once()->with('アプリケーションで例外が発生しました。', \Mockery::on(fn ($context) => array_keys($context) === ['occurred_at', 'route', 'exception_type'] && $context['route'] === 'books/{book}/favorites' && $context['exception_type'] === QueryException::class));
    }

    /** 同一書籍の直列化と別書籍の同時追加を確認する。 */
    public static function races(): array
    {
        return [
            'same-add' => ['same', false], 'same-remove' => ['same', true],
            'other-add' => ['other', false], 'other-remove' => ['other', true],
            'distinct-same-user' => ['distinct', false], 'distinct-other-user' => ['distinct-other', false],
            'delete-first' => ['delete-first', false], 'delete-last-added' => ['delete-last', false],
            'delete-last-removed' => ['delete-last', true],
        ];
    }

    /** T-9-9：独立HTTP処理を同期し、待機後の確定値・日時・全保存値を照合する。 */
    #[DataProvider('races')]
    public function test_concurrent_toggles_and_deletion_preserve_committed_state(string $case, bool $initial): void
    {
        config(['database.connections.favorite_race' => config('database.connections.mysql')]);
        $connection = DB::connection('favorite_race');
        $this->assertSame('testing', $connection->getDatabaseName());
        $this->assertSame('REPEATABLE-READ', $connection->selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
        $users = [];
        $books = [];
        $processes = [];
        $streams = [];
        try {
            foreach (range(1, 3) as $unused) {
                $user = User::factory()->make();
                $user->setConnection('favorite_race')->save();
                $users[] = $user;
                $book = Book::factory()->make(['user_id' => $user->id]);
                $book->setConnection('favorite_race')->save();
                $books[] = $book;
            }
            // 先頭の既存行より大きいIDに未登録2組を配置し、同じ索引末尾ギャップを検索する。
            $connection->table('favorites')->insert(['user_id' => $users[0]->id, 'book_id' => $books[0]->id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            $userId = $users[1]->id;
            $book = $books[1];
            if ($initial) {
                $connection->table('favorites')->insert(['user_id' => $userId, 'book_id' => $book->id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            }
            $before = $this->snapshot($connection);
            $start = function (int $targetId, int $actorId, string $operation, string $pause) use (&$processes, &$streams): Process {
                $input = new InputStream;
                $process = new Process([PHP_BINARY, '-r', $this->concurrentScript(), (string) $targetId, (string) $actorId, $operation, $pause], base_path(), ['APP_ENV' => 'testing'], $input, 15);
                $streams[] = $input;
                $processes[] = $process;
                $process->start();

                return $process;
            };
            $responseFor = fn ($target) => ['status' => 302, 'location' => route('books.show', $target), 'success' => null];
            if ($case === 'delete-first') {
                $toggle = $start($book->id, $userId, 'toggle', 'bound');
                $this->waitForMarker($toggle, 'bound');
                $connection->beginTransaction();
                $connection->table('books')->where('id', $book->id)->delete();
                $expected = $this->snapshot($connection);
                $streams[0]->write("continue\n");
                $this->waitForSqlInProcessList($connection, $toggle, 'for update');
                $connection->commit();
                $this->assertSame(404, $this->finish($toggle)['status']);
                $this->assertSame($expected, $this->snapshot($connection));
            } elseif (str_starts_with($case, 'distinct')) {
                $pairs = [[$userId, $book->id], [$users[$case === 'distinct' ? 1 : 2]->id, $books[2]->id]];
                foreach ($pairs as [$actorId, $targetId]) {
                    $process = $start($targetId, $actorId, 'toggle', 'selected');
                    $this->waitForMarker($process, 'selected');
                }
                $this->assertSame($before, $this->snapshot($connection));
                foreach ($streams as $stream) {
                    $stream->write("continue\n");
                }
                foreach ($processes as $process) {
                    $this->waitForMarker($process, 'released');
                }
                foreach ($processes as $index => $process) {
                    $this->assertSame($responseFor($books[$index + 1]), $this->finish($process));
                }
                $this->assertSame($before, $this->withoutPairs($this->snapshot($connection), $pairs));
                foreach ($pairs as [$actorId, $targetId]) {
                    $this->assertSavedPair($connection, $actorId, $targetId);
                }
            } else {
                $first = $start($book->id, $userId, 'toggle', 'written');
                $this->waitForMarker($first, 'written');
                $secondId = $case === 'other' ? $users[2]->id : $userId;
                $deleting = $case === 'delete-last';
                $second = $start($book->id, $deleting ? $book->user_id : $secondId, $deleting ? 'delete' : 'toggle', $deleting ? 'deleting' : 'written');
                $this->waitForSqlInProcessList($connection, $second, 'for update');
                $streams[0]->write("continue\n");
                $this->assertSame($responseFor($book), $this->finish($first));
                $this->waitForMarker($second, $deleting ? 'deleting' : 'written');
                $pairs = [[$userId, $book->id]];
                $this->assertSame($this->withoutPairs($before, $pairs), $this->withoutPairs($this->snapshot($connection), $pairs));
                if ($initial) {
                    $this->assertSame(0, $connection->table('favorites')->where('user_id', $userId)->where('book_id', $book->id)->count());
                } else {
                    $this->assertSavedPair($connection, $userId, $book->id);
                }
                $streams[1]->write("continue\n");
                $response = $this->finish($second);
                if ($deleting) {
                    $this->assertSame(['status' => 302, 'location' => route('books.index'), 'success' => '書籍を削除しました。'], $response);
                    $expected = $before;
                    foreach (['books', 'favorites'] as $table) {
                        $expected[$table] = collect(json_decode($before[$table]))->reject(fn ($row) => ($table === 'books' ? $row->id : $row->book_id) === $book->id)->values()->toJson();
                    }
                    $this->assertSame($expected, $this->snapshot($connection));
                } else {
                    $this->assertSame($responseFor($book), $response);
                    $pairs[] = [$secondId, $book->id];
                    $this->assertSame($this->withoutPairs($before, $pairs), $this->withoutPairs($this->snapshot($connection), $pairs));
                    $saved = $connection->table('favorites')->where('book_id', $book->id)->orderBy('user_id')->get();
                    $this->assertSame($case === 'same' ? ($initial ? [$userId] : []) : ($initial ? [$secondId] : [$userId, $secondId]), $saved->pluck('user_id')->all());
                    foreach ($saved as $row) {
                        $this->assertSavedPair($connection, $row->user_id, $row->book_id);
                    }
                }
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach ($streams as $stream) {
                $stream->close();
            }
            if ($connection->transactionLevel()) {
                $connection->rollBack();
            }
            $connection->table('users')->whereIn('id', array_map(fn ($user) => $user->id, $users))->delete();
            DB::purge('favorite_race');
        }
    }

    /** 追加された組の一意性と日時を含めた全列を照合する。 */
    private function assertSavedPair($connection, int $userId, int $bookId): void
    {
        $saved = $connection->table('favorites')->where('user_id', $userId)->where('book_id', $bookId)->get();
        $this->assertCount(1, $saved);
        $row = (array) $saved->first();
        $this->assertSame(['id' => $row['id'], 'user_id' => $userId, 'book_id' => $bookId, 'created_at' => '2030-01-02 03:04:05', 'updated_at' => '2030-01-02 03:04:05'], $row);
    }

    /** 同期点への到達をタイムアウト付きで確認する。 */
    private function waitForMarker(Process $process, string $marker): void
    {
        $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, $marker."\n")), $process->getOutput().$process->getErrorOutput());
    }

    /** 正常終了した子のHTTP応答を取り出す。 */
    private function finish(Process $process): array
    {
        $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    /** 実経路と同じトランザクション境界の独立HTTP処理を明示同期する。 */
    private function concurrentScript(): string
    {
        return <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'testing') { exit(2); }
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 8');
Illuminate\Support\Carbon::setTestNow('2030-01-02 03:04:05');
Illuminate\Support\Facades\Auth::guard()->setUser(App\Models\User::findOrFail($argv[2]));
$pause = function ($marker) {
    echo $marker."\n"; fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'continue') { exit(3); }
    echo "released\n"; fflush(STDOUT);
};
if ($argv[4] === 'bound') {
    App\Models\Book::retrieved(function () use ($pause) { static $first = true; if ($first) { $first = false; $pause('bound'); } });
} elseif ($argv[4] === 'selected') {
    Illuminate\Support\Facades\DB::listen(function ($query) use ($pause) {
        if (preg_match('/^select .* from `favorites`/i', $query->sql)) { $pause('selected'); }
    });
} elseif ($argv[4] === 'written') {
    Illuminate\Support\Facades\DB::listen(function ($query) use ($pause) {
        if (preg_match('/^(insert into|delete from) `favorites`/i', $query->sql)) { $pause('written'); }
    });
} else {
    App\Models\Book::deleting(fn () => $pause('deleting'));
}
$url = '/books/'.$argv[1].($argv[3] === 'toggle' ? '/favorites' : '');
$request = Illuminate\Http\Request::create($url, $argv[3] === 'toggle' ? 'POST' : 'DELETE');
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
session()->start();
echo json_encode(['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'success' => session('success')], JSON_UNESCAPED_UNICODE)."\n";
CHILD;
    }
}

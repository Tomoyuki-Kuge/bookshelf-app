<?php

/** レビューいいねの表示・認証・原子保存と独立プロセスの排他を検証する。 */

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

class ReviewLikeTest extends TestCase
{
    use InteractsWithRenderedHtml;
    use ObservesConcurrentSql;

    /** testing限定で準備し、通常ケースはトランザクションで隔離する。 */
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::beginTransaction();
        $this->withoutVite();
    }

    /** テストの保存値を戻す。 */
    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /** 全業務表の全保存値を取得し、変更可能な組だけを必要に応じて除外する。 */
    private function snapshot($connection = null, ?array $exceptPair = null): array
    {
        $connection ??= DB::connection();

        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])
            ->mapWithKeys(fn ($table) => [$table => $connection->table($table)->orderBy('id')->get()
                ->reject(fn ($row) => $table === 'review_likes' && $exceptPair && $row->user_id === $exceptPair[0] && $row->review_id === $exceptPair[1])->values()->toJson()])->all();
    }

    /** 対象フォームの状態と件数を、他のレビューの表示と混ぜず照合する。 */
    private function assertLikeButton(User $user, Review $review, string $text): void
    {
        // 実際の次リクエストと同様に認証モデルを再取得する。
        $response = $this->actingAs($user->fresh())->get(route('books.show', $review->book_id))->assertOk();
        $xpath = $this->createDomXPath($response);
        $buttons = $xpath->query('//form[@action="'.route('reviews.like', $review).'"]/button');
        $this->assertCount(1, $buttons);
        $this->assertSame($text, preg_replace('/\s+/u', ' ', trim($buttons[0]->textContent)));
    }

    /** AC-8-01〜03 / T-8-1〜3・5：自己・他者、0→1→0、送信ID無視と対象外不変。 */
    public function test_toggle_uses_authenticated_user_and_route_and_renders_current_counts(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->create();
        $review = Review::factory()->for($user)->for($book)->create();
        $unrelated = Review::factory()->create();
        $other->likedReviews()->attach($unrelated);
        $book->genres()->attach(Genre::factory()->create());
        $other->favoriteBooks()->attach($unrelated->book_id);
        $pair = [$user->id, $review->id];
        $before = $this->snapshot(exceptPair: $pair);
        $this->assertLikeButton($user, $review, 'いいね (0)');
        $this->travelTo(now()->setDate(2030, 1, 2)->setTime(3, 4, 5));
        foreach ([true, false, true, false] as $adding) {
            // DBと反対の状態を残し、古い関連キャッシュによる判断を検知する。
            $user->setRelation('likedReviews', $adding ? collect([$review]) : collect());
            $this->actingAs($user)->post(route('reviews.like', $review), ['user_id' => $other->id, 'review_id' => $unrelated->id, 'book_id' => $unrelated->book_id])
                ->assertRedirect(route('books.show', $book))->assertSessionMissing('success')->assertSessionMissing('status')->assertSessionHasNoErrors();
            $rows = DB::table('review_likes')->where('user_id', $user->id)->where('review_id', $review->id)->get();
            $this->assertCount($adding ? 1 : 0, $rows);
            if ($adding) {
                $row = (array) $rows->first();
                $this->assertSame(['id' => $row['id'], 'user_id' => $user->id, 'review_id' => $review->id, 'created_at' => '2030-01-02 03:04:05', 'updated_at' => '2030-01-02 03:04:05'], $row);
            }
            $this->assertSame($before, $this->snapshot(exceptPair: $pair));
            $this->assertLikeButton($user, $review, $adding ? 'いいね済み (1)' : 'いいね (0)');
        }
        $other->likedReviews()->attach($review);
        $before = $this->snapshot(exceptPair: $pair);
        $this->actingAs($user)->post(route('reviews.like', $review))->assertRedirect(route('books.show', $book));
        $this->assertLikeButton($user, $review, 'いいね済み (2)');
        $this->assertLikeButton($other, $review, 'いいね済み (2)');
        $this->actingAs($user)->post(route('reviews.like', $review))->assertRedirect(route('books.show', $book));
        $this->assertLikeButton($user, $review, 'いいね (1)');
        $this->assertLikeButton($other, $review, 'いいね済み (1)');
        $this->assertSame($before, $this->snapshot(exceptPair: $pair));
        // 他人のレビューへの操作も本人Policyで拒否しない。
        $this->actingAs($other)->post(route('reviews.like', $review))->assertRedirect(route('books.show', $book));
        $this->assertLikeButton($other, $review, 'いいね (0)');
        $this->travelBack();
    }

    /** T-8-4：ゲスト表示・直接POSTと不存在／削除済みの拒否で保存値を変えない。 */
    public function test_guest_and_missing_targets_preserve_every_saved_value(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();
        $user->likedReviews()->attach($review);
        $before = $this->snapshot();
        $response = $this->get(route('books.show', $review->book_id))->assertOk();
        $xpath = $this->createDomXPath($response);
        $links = $xpath->query('//a[@href="'.route('login').'" and contains(., "いいね (1)")]');
        $this->assertCount(1, $links);
        foreach ([$review->id, 999999999] as $id) {
            $this->post(route('reviews.like', $id))->assertRedirect(route('login'));
            $this->assertSame($before, $this->snapshot());
        }
        $this->actingAs($user)->post(route('reviews.like', 999999999))->assertNotFound();
        $this->assertSame($before, $this->snapshot());
        $review->delete();
        $before = $this->snapshot();
        $this->post(route('reviews.like', $review->id))->assertNotFound();
        $this->assertSame($before, $this->snapshot());
    }

    /** T-8-5：Webレビュー削除で対象いいねだけがCASCADEされる。 */
    public function test_web_review_delete_cascades_likes_and_preserves_other_reviews(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create();
        $other = Review::factory()->create();
        $user->likedReviews()->attach([$review->id, $other->id]);
        $before = $this->snapshot();
        $this->actingAs($user)->delete(route('reviews.destroy', $review))->assertRedirect(route('books.show', $review->book_id));
        $after = $this->snapshot();
        foreach (['reviews', 'review_likes'] as $table) {
            $before[$table] = collect(json_decode($before[$table]))->reject(fn ($row) => ($table === 'reviews' ? $row->id : $row->review_id) === $review->id)->values()->toJson();
        }
        $this->assertSame($before, $after);
    }

    /** 追加・解除の双方で書き込み後障害を発生させる。 */
    public static function initialStates(): array
    {
        return [[false], [true]];
    }

    /** T-8-6：実際の中間行変更後の障害を全体取消し、安全な500とログへ渡す。 */
    #[DataProvider('initialStates')]
    public function test_failure_after_write_rolls_back_without_success(bool $initiallyLiked): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();
        // 空の関連表では見逃す、対象外データの誤削除・日時変更も検出する。
        $otherUser = User::factory()->create();
        $otherReview = Review::factory()->for($otherUser)->create();
        $originalTimes = ['created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'];
        $otherUser->likedReviews()->attach([$review->id => $originalTimes, $otherReview->id => $originalTimes]);
        $otherUser->favoriteBooks()->attach($otherReview->book_id, $originalTimes);
        $otherReview->book->genres()->attach(Genre::factory()->create(), $originalTimes);
        if ($initiallyLiked) {
            $user->likedReviews()->attach($review);
        }
        $before = $this->snapshot();
        $logger = Log::spy();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && preg_match('/^(insert into|delete from) `review_likes`/i', $query->sql)) {
                $armed = false;
                throw new QueryException('mysql', 'SQL-secret', ['password-secret'], new \PDOException('unexpected'));
            }
        });
        $this->actingAs($user)->post(route('reviews.like', $review))->assertStatus(500)->assertDontSee('SQL-secret')->assertDontSee('password-secret')->assertSessionMissing('success');
        $this->assertFalse($armed);
        $this->assertSame($before, $this->snapshot());
        $logger->shouldHaveReceived('error')->once()->with('アプリケーションで例外が発生しました。', \Mockery::on(fn ($context) => array_keys($context) === ['occurred_at', 'route', 'exception_type'] && $context['route'] === 'reviews/{review}/like' && $context['exception_type'] === QueryException::class));
    }

    /** 同一組・別利用者、削除前後の実並行ケース。 */
    public static function races(): array
    {
        return [
            'same-add' => ['same', false], 'same-remove' => ['same', true],
            'other-add' => ['other', false], 'other-remove' => ['other', true],
            'review-delete-first' => ['review-first', false], 'book-delete-first' => ['book-first', false],
            'like-before-review-delete' => ['review-last', false], 'like-before-book-delete' => ['book-last', true],
        ];
    }

    /** T-8-6：独立プロセスの待機SQLと確定保存値を同期して検証する。 */
    #[DataProvider('races')]
    public function test_concurrent_toggles_and_deletions_are_serialized(string $case, bool $initiallyLiked): void
    {
        config(['database.connections.like_race' => config('database.connections.mysql')]);
        $connection = DB::connection('like_race');
        $this->assertSame('testing', $connection->getDatabaseName());
        $users = [];
        $processes = [];
        $streams = [];
        try {
            foreach (range(1, 2) as $unused) {
                $user = User::factory()->make();
                $user->setConnection('like_race')->save();
                $users[] = $user;
            }
            $book = Book::factory()->make(['user_id' => $users[0]->id]);
            $book->setConnection('like_race')->save();
            $review = new Review(['user_id' => $users[0]->id, 'book_id' => $book->id, 'rating' => 3, 'comment' => '競合対象']);
            $review->setConnection('like_race')->save();
            $other = new Review(['user_id' => $users[1]->id, 'book_id' => $book->id, 'rating' => 4, 'comment' => '対象外']);
            $other->setConnection('like_race')->save();
            $connection->table('review_likes')->insert(['user_id' => $users[1]->id, 'review_id' => $other->id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            if ($initiallyLiked) {
                $connection->table('review_likes')->insert(['user_id' => $users[0]->id, 'review_id' => $review->id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            }
            // 親書籍の削除後にも残る、別書籍のいいねを保存値比較に含める。
            $unrelatedBook = Book::factory()->make(['user_id' => $users[1]->id]);
            $unrelatedBook->setConnection('like_race')->save();
            $unrelatedReview = new Review(['user_id' => $users[1]->id, 'book_id' => $unrelatedBook->id, 'rating' => 2, 'comment' => '別書籍の対象外']);
            $unrelatedReview->setConnection('like_race')->save();
            $connection->table('review_likes')->insert(['user_id' => $users[1]->id, 'review_id' => $unrelatedReview->id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            $before = $this->snapshot($connection);
            $firstDelete = str_ends_with($case, '-first');
            $lastDelete = str_ends_with($case, '-last');
            $start = function (string $operation, string $pause, int $userId) use ($review, &$processes, &$streams): Process {
                $input = new InputStream;
                $process = new Process([PHP_BINARY, '-r', $this->concurrentScript(), (string) $review->id, (string) $userId, $operation, $pause], base_path(), ['APP_ENV' => 'testing'], $input, 15);
                $streams[] = $input;
                $processes[] = $process;
                $process->start();

                return $process;
            };
            if ($firstDelete) {
                // 経路解決後に削除を先行させ、Controller内の存在再確認を実際に待機させる。
                $toggle = $start('like', 'bound', $users[0]->id);
                $this->waitForMarker($toggle, 'bound');
                $connection->beginTransaction();
                $connection->table(str_starts_with($case, 'book') ? 'books' : 'reviews')->where('id', str_starts_with($case, 'book') ? $book->id : $review->id)->delete();
                // 子の待機を解除する前に、削除だけを反映した期待値を固定する。
                $expected = $this->snapshot($connection);
                $streams[0]->write("continue\n");
                $this->waitForSqlInProcessList($connection, $toggle, str_starts_with($case, 'book') ? 'lock in share mode' : 'for update');
                $connection->commit();
                $this->assertSame(404, $this->finish($toggle)['status']);
                $this->assertSame($expected, $this->snapshot($connection));
            } else {
                $first = $start('like', 'written', $users[0]->id);
                $this->waitForMarker($first, 'written');
                $secondOperation = $lastDelete ? (str_starts_with($case, 'book') ? 'book-delete' : 'review-delete') : 'like';
                $second = $start($secondOperation, $lastDelete ? 'deleting' : 'written', $case === 'other' ? $users[1]->id : $users[0]->id);
                $this->waitForSqlInProcessList($connection, $second, 'for update');
                $streams[0]->write("continue\n");
                $firstResponse = $this->finish($first);
                $this->assertSame(['status' => 302, 'location' => route('books.show', $book), 'success' => null], $firstResponse);
                $this->waitForMarker($second, $lastDelete ? 'deleting' : 'written');
                // 第2操作は未コミット。第1操作の確定値と対象外全保存値を先に照合する。
                $pair = [$users[0]->id, $review->id];
                $expected = $before;
                $expected['review_likes'] = collect(json_decode($before['review_likes']))->reject(fn ($row) => $row->user_id === $pair[0] && $row->review_id === $pair[1])->values()->toJson();
                $this->assertSame($expected, $this->snapshot($connection, $pair));
                $saved = $connection->table('review_likes')->where('review_id', $review->id)->get();
                $this->assertCount($initiallyLiked ? 0 : 1, $saved);
                if (! $initiallyLiked) {
                    $row = (array) $saved->first();
                    $this->assertSame(['id' => $row['id'], 'user_id' => $users[0]->id, 'review_id' => $review->id, 'created_at' => '2030-01-02 03:04:05', 'updated_at' => '2030-01-02 03:04:05'], $row);
                }
                $streams[1]->write("continue\n");
                $secondResponse = $this->finish($second);
                if ($secondOperation === 'like') {
                    $this->assertSame(['status' => 302, 'location' => route('books.show', $book), 'success' => null], $secondResponse);
                } else {
                    $this->assertSame(302, $secondResponse['status']);
                }
                if ($lastDelete) {
                    $this->assertFalse($connection->table('reviews')->where('id', $review->id)->exists());
                    $this->assertSame(0, $connection->table('review_likes')->where('review_id', $review->id)->count());
                    // 削除対象以外を全保存値で比較する。
                    $expected = $before;
                    foreach (['books', 'reviews', 'review_likes'] as $table) {
                        $expected[$table] = collect(json_decode($before[$table]))->reject(fn ($row) => match ($table) {
                            'books' => $case === 'book-last' && $row->id === $book->id,
                            'reviews' => $case === 'book-last' ? $row->book_id === $book->id : $row->id === $review->id,
                            'review_likes' => in_array($row->review_id, $case === 'book-last' ? [$review->id, $other->id] : [$review->id]),
                        })->values()->toJson();
                    }
                    $this->assertSame($expected, $this->snapshot($connection));
                } else {
                    $likes = $connection->table('review_likes')->where('review_id', $review->id)->orderBy('user_id')->get();
                    $this->assertSame($case === 'same' ? ($initiallyLiked ? [$users[0]->id] : []) : ($initiallyLiked ? [$users[1]->id] : [$users[0]->id, $users[1]->id]), $likes->pluck('user_id')->all());
                    // 2回目の確定後も、対象レビューの組以外は全保存値を維持する。
                    $withoutTargetLikes = function (array $snapshot) use ($review): array {
                        $snapshot['review_likes'] = collect(json_decode($snapshot['review_likes']))->reject(fn ($row) => $row->review_id === $review->id)->values()->toJson();

                        return $snapshot;
                    };
                    $this->assertSame($withoutTargetLikes($before), $withoutTargetLikes($this->snapshot($connection)));
                    foreach ($likes as $like) {
                        $this->assertSame('2030-01-02 03:04:05', $like->created_at);
                        $this->assertSame($like->created_at, $like->updated_at);
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
            DB::purge('like_race');
        }
    }

    /** 別レビューへの追加を同じ利用者・別利用者で検証する。 */
    public static function distinctReviewUsers(): array
    {
        return ['same-user' => [true], 'different-users' => [false]];
    }

    /** T-8-6：同じ未登録ギャップを検索済みの2処理を同期し、双方の追加を確定する。 */
    #[DataProvider('distinctReviewUsers')]
    public function test_initial_likes_on_distinct_reviews_do_not_deadlock(bool $sameUser): void
    {
        config(['database.connections.distinct_likes' => config('database.connections.mysql')]);
        $connection = DB::connection('distinct_likes');
        $this->assertSame('testing', $connection->getDatabaseName());
        $this->assertSame('REPEATABLE-READ', $connection->selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level);
        $users = [];
        $processes = [];
        $streams = [];
        try {
            foreach (range(1, 3) as $unused) {
                $user = User::factory()->make();
                $user->setConnection('distinct_likes')->save();
                $users[] = $user;
            }
            $reviews = [];
            foreach ($users as $user) {
                $book = Book::factory()->make(['user_id' => $user->id]);
                $book->setConnection('distinct_likes')->save();
                $review = new Review(['user_id' => $user->id, 'book_id' => $book->id, 'rating' => 3, 'comment' => '別レビュー並行追加']);
                $review->setConnection('distinct_likes')->save();
                $reviews[] = $review;
            }
            // 既存の組より大きい利用者IDに2組を配置し、同じ末尾ギャップへ到達させる。
            $connection->table('review_likes')->insert(['user_id' => $users[0]->id, 'review_id' => $reviews[0]->id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
            $before = $this->snapshot($connection);
            $pairs = [[$users[1]->id, $reviews[1]->id], [$users[$sameUser ? 1 : 2]->id, $reviews[2]->id]];
            foreach ($pairs as [$userId, $reviewId]) {
                $input = new InputStream;
                $process = new Process([PHP_BINARY, '-r', $this->concurrentScript(), (string) $reviewId, (string) $userId, 'like', 'selected'], base_path(), ['APP_ENV' => 'testing'], $input, 15);
                $streams[] = $input;
                $processes[] = $process;
                $process->start();
                $this->waitForMarker($process, 'selected');
            }
            // 両検索の完了を確認してから、どちらの終了も待たず両方のINSERTを解放する。
            $this->assertSame($before, $this->snapshot($connection));
            foreach ($streams as $stream) {
                $stream->write("continue\n");
            }
            // 遅延送信されるInputStreamを両方送信し、子の受信確認後に終了を待つ。
            foreach ($processes as $process) {
                $this->waitForMarker($process, 'released');
            }
            $responses = array_map(fn ($process) => $this->finish($process), $processes);
            foreach ($responses as $index => $response) {
                $this->assertSame(['status' => 302, 'location' => route('books.show', $reviews[$index + 1]->book_id), 'success' => null], $response);
            }
            $saved = $connection->table('review_likes')->whereIn('review_id', [$reviews[1]->id, $reviews[2]->id])->orderBy('review_id')->get();
            $this->assertCount(2, $saved);
            foreach ($saved as $index => $row) {
                $this->assertSame(['id' => $row->id, 'user_id' => $pairs[$index][0], 'review_id' => $pairs[$index][1], 'created_at' => '2030-01-02 03:04:05', 'updated_at' => '2030-01-02 03:04:05'], (array) $row);
            }
            $after = $this->snapshot($connection);
            $after['review_likes'] = collect(json_decode($after['review_likes']))->reject(fn ($row) => in_array([$row->user_id, $row->review_id], $pairs, true))->values()->toJson();
            $this->assertSame($before, $after);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach ($streams as $stream) {
                $stream->close();
            }
            $connection->table('users')->whereIn('id', array_map(fn ($user) => $user->id, $users))->delete();
            DB::purge('distinct_likes');
        }
    }

    /** 子プロセスの同期点に到達したことをタイムアウト付きで確認する。 */
    private function waitForMarker(Process $process, string $marker): void
    {
        $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, $marker."\n")), $process->getOutput().$process->getErrorOutput());
    }

    /** 子プロセスの正常終了とHTTP応答を取り出す。 */
    private function finish(Process $process): array
    {
        $this->assertSame(0, $process->wait(), $process->getErrorOutput());
        $lines = explode("\n", trim($process->getOutput()));

        return json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
    }

    /** 公開testing設定の独立HTTP処理を、ロック／変更後の明示同期で動かす。 */
    private function concurrentScript(): string
    {
        return <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'testing') { exit(2); }
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 8');
Illuminate\Support\Carbon::setTestNow('2030-01-02 03:04:05');
$review = App\Models\Review::findOrFail($argv[1]);
Illuminate\Support\Facades\Auth::guard()->setUser(App\Models\User::findOrFail($argv[2]));
$pause = function ($marker) {
    echo $marker."\n"; fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'continue') { exit(3); }
    echo "released\n"; fflush(STDOUT);
};
if ($argv[4] === 'bound') {
    App\Models\Review::retrieved(function () use ($pause) { static $first = true; if ($first) { $first = false; $pause('bound'); } });
} elseif ($argv[4] === 'selected') {
    Illuminate\Support\Facades\DB::listen(function ($query) use ($pause) {
        if (preg_match('/^select .* from `review_likes`/i', $query->sql)) { $pause('selected'); }
    });
} elseif ($argv[4] === 'written') {
    Illuminate\Support\Facades\DB::listen(function ($query) use ($pause) {
        if (preg_match('/^(insert into|delete from) `review_likes`/i', $query->sql)) { $pause('written'); }
    });
} elseif ($argv[3] === 'book-delete') {
    App\Models\Book::deleting(fn () => $pause('deleting'));
} else {
    App\Models\Review::deleting(fn () => $pause('deleting'));
}
$url = match ($argv[3]) { 'like' => '/reviews/'.$review->id.'/like', 'book-delete' => '/books/'.$review->book_id, default => '/reviews/'.$review->id };
$request = Illuminate\Http\Request::create($url, $argv[3] === 'like' ? 'POST' : 'DELETE');
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
session()->start();
echo json_encode(['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'success' => session('success')], JSON_UNESCAPED_UNICODE)."\n";
CHILD;
    }
}

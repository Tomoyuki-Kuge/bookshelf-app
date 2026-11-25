<?php

/** レビュー管理の入力・本人認可・原子保存・物理削除と競合を検証する。 */

namespace Tests\Feature;

use App\Http\Requests\ReviewRequest;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReviewManagementTest extends TestCase
{
    /** testing限定でテスト自身のデータを作成する。 */
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::beginTransaction();
        $this->withoutVite();
    }

    /** 模擬イベント・トランザクションを後片付けする。 */
    protected function tearDown(): void
    {
        Review::flushEventListeners();
        DB::rollBack();
        parent::tearDown();
    }

    /** 関連を含む全業務表の保存値を比較する。 */
    private function snapshot(): array
    {
        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    /** 変更を許す対象レビューと削除時のいいね以外を比較する。 */
    private function unrelated(array $snapshot, int $id, bool $delete = false): array
    {
        foreach ($snapshot as $table => $json) {
            $snapshot[$table] = array_values(array_filter(json_decode($json, true), fn ($row) => ! (($table === 'reviews' && $row['id'] === $id) || ($delete && $table === 'review_likes' && $row['review_id'] === $id))));
        }

        return $snapshot;
    }

    /** 欄下文言とフォーム値をDOMから取得する。 */
    private function xpath($response): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    /** T-7-1・2・4・5・8・10 / AC-7-01・02：各成功直後の全項目と対象外不変を照合する。 */
    public function test_post_edit_update_preserve_identity_and_unrelated_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $ownBook = Book::factory()->for($owner)->create();
        $otherBook = Book::factory()->for($other)->create();
        $genre = Genre::factory()->create();
        $ownBook->genres()->attach($genre);
        $other->favoriteBooks()->attach($ownBook);
        $unrelated = Review::factory()->for($other)->for($otherBook)->create();
        $owner->likedReviews()->attach($unrelated);
        $this->actingAs($owner);
        foreach ([$ownBook, $otherBook, $ownBook] as $index => $book) {
            $input = ['rating' => $index === 1 ? '5' : '1', 'comment' => str_repeat('あ', 1000), 'user_id' => $other->id, 'book_id' => ($book->id === $ownBook->id ? $otherBook : $ownBook)->id];
            $before = $this->snapshot();
            $this->post(route('reviews.store', $book), $input)->assertRedirect(route('books.show', $book))->assertSessionHas('success', 'レビューを登録しました。');
            $review = Review::orderByDesc('id')->firstOrFail();
            $this->assertSame(['user_id' => $owner->id, 'book_id' => $book->id, 'rating' => (int) $input['rating'], 'comment' => $input['comment']], $review->only(['user_id', 'book_id', 'rating', 'comment']));
            $this->assertNotNull($review->created_at);
            $this->assertNotNull($review->updated_at);
            $this->assertSame($this->unrelated($before, $review->id), $this->unrelated($this->snapshot(), $review->id));
            $this->get(route('books.show', $book))->assertSee('レビューを登録しました。')->assertSee(route('reviews.edit', $review), false);
            $other->likedReviews()->attach($review);
            $before = $this->snapshot();
            $edit = $this->get(route('reviews.edit', $review))->assertOk()->assertSee($book->title)->assertSee(route('books.show', $book), false);
            $dom = $this->xpath($edit);
            $this->assertSame($input['rating'], $dom->query('//input[@name="rating"][@checked]')->item(0)->getAttribute('value'));
            $this->assertSame($input['comment'], $dom->query('//textarea[@name="comment"]')->item(0)->textContent);
            $this->assertSame($before, $this->snapshot());
            $identity = $review->only(['id', 'user_id', 'book_id', 'created_at']);
            $this->travel(2)->seconds();
            $changed = ['rating' => $index === 1 ? '1' : '5', 'comment' => $index === 0 ? str_repeat('い', 1000) : '変更後'.$index, 'user_id' => $other->id, 'book_id' => $input['book_id']];
            $this->put(route('reviews.update', $review), $changed)->assertRedirect(route('books.show', $book))->assertSessionHas('success', 'レビューを更新しました。');
            $saved = $review->fresh();
            $this->assertEquals($identity, $saved->only(array_keys($identity)));
            $this->assertSame((int) $changed['rating'], $saved->rating);
            $this->assertSame($changed['comment'], $saved->comment);
            $this->assertTrue($saved->updated_at->greaterThan($review->updated_at));
            $this->assertSame($this->unrelated($before, $review->id), $this->unrelated($this->snapshot(), $review->id));
            $this->get(route('books.show', $book))->assertSee('レビューを更新しました。')->assertSee($changed['comment']);
        }
        $this->assertSame(2, Review::where('user_id', $owner->id)->where('book_id', $ownBook->id)->count());
        $this->travelBack();
    }

    /** T-7-3・4・6：実装に依存しない固定の期待文言と境界を返す。 */
    public static function invalidInputs(): array
    {
        $cases = [];
        foreach ([null, '', [], '@omitted'] as $value) {
            $cases[] = ['rating', $value, '評価は必須です。'];
            $cases[] = ['comment', $value, 'コメントは必須です。'];
        }
        foreach (['abc', '1.5', ['1'], 0, 6, true, 1.0] as $value) {
            $cases[] = ['rating', $value, '評価は1〜5の整数で入力してください。'];
        }
        foreach ([123, ['不正'], true] as $value) {
            $cases[] = ['comment', $value, 'コメントは文字列で入力してください。'];
        }
        $cases[] = ['comment', str_repeat('あ', 1001), 'コメントは1000文字以内で入力してください。'];

        return $cases;
    }

    /** T-7-3・4・6 / AC-7-03：POST/PUTの欄下エラー・旧入力・全保存値不変を確認する。 */
    #[DataProvider('invalidInputs')]
    public function test_invalid_input_returns_to_internal_form_with_safe_old_input(string $field, mixed $value, string $message): void
    {
        $this->actingAs($owner = User::factory()->create());
        $book = Book::factory()->create();
        $review = Review::factory()->for($owner)->for($book)->create(['rating' => 4, 'comment' => '保存済み']);
        // 関連表も非空にして、入力拒否時の誤削除・変更を検出する。
        $other = User::factory()->create();
        $otherBook = Book::factory()->for($other)->create();
        $otherReview = Review::factory()->for($other)->for($otherBook)->create();
        $owner->likedReviews()->attach([$review->id, $otherReview->id]);
        $other->likedReviews()->attach([$review->id, $otherReview->id]);
        $owner->favoriteBooks()->attach([$book->id, $otherBook->id]);
        $other->favoriteBooks()->attach($book);
        $genres = Genre::factory()->count(2)->create();
        $book->genres()->attach($genres->modelKeys());
        $otherBook->genres()->attach($genres->first());
        foreach (['POST', 'PUT'] as $method) {
            $data = array_replace(['rating' => '3', 'comment' => '保持するコメント'], [$field => $value]);
            if ($value === '@omitted') {
                unset($data[$field]);
            }
            $rating = $data['rating'] ?? null;
            $expected = ['rating' => is_string($rating) || is_int($rating) ? $rating : '', 'comment' => is_string($data['comment'] ?? null) ? $data['comment'] : ''];
            $url = $method === 'POST' ? route('reviews.store', $book) : route('reviews.update', $review);
            $form = $method === 'POST' ? route('books.show', $book) : route('reviews.edit', $review);
            $before = $this->snapshot();
            $this->from('https://outside.example/')->call($method, $url, $data + ['password' => 'secret', 'user_id' => 999])->assertRedirect($form);
            $this->assertSame($expected, $this->app['session.store']->getOldInput());
            $response = $this->get($form)->assertOk();
            $this->assertSame([$field => [$message]], $this->app['view']->shared('errors')->getBag('default')->toArray());
            $dom = $this->xpath($response);
            $this->assertSame($expected['comment'], $dom->query('//textarea[@name="comment"]')->item(0)->textContent);
            $selected = $dom->query($method === 'POST' ? '//select[@name="rating"]/option[@selected]' : '//input[@name="rating"][@checked]');
            $expectedRating = in_array((string) $expected['rating'], ['1', '2', '3', '4', '5'], true) ? (string) $expected['rating'] : '';
            $actualRating = $selected->length ? $selected->item(0)->getAttribute('value') : '';
            $this->assertSame($expectedRating, $actualRating);
            foreach (['rating', 'comment'] as $name) {
                $node = $dom->query('//*[@name="'.$name.'"]')->item(0);
                $container = $name === 'rating' && $method === 'PUT' ? $node->parentNode->parentNode->parentNode : $node->parentNode;
                $messages = array_map(fn ($node) => trim($node->textContent), iterator_to_array($dom->query('./p[contains(@class,"text-red-600")]', $container)));
                $this->assertSame($name === $field ? [$message] : [], $messages, $name.'欄下');
            }
            $this->assertSame($before, $this->snapshot());
        }
    }

    /** T-7-7〜9：認証→対象→本人→入力の順序と拒否後全値不変を確認する。 */
    public function test_authentication_target_and_owner_checks_precede_validation(): void
    {
        $owner = User::factory()->create();
        $bookOwner = User::factory()->create();
        $stranger = User::factory()->create();
        $book = Book::factory()->for($bookOwner)->create();
        $review = Review::factory()->for($owner)->for($book)->create();
        $bookOwner->likedReviews()->attach($review);
        $requests = [['POST', '/books/'.$book->id.'/reviews'], ['GET', '/reviews/'.$review->id.'/edit'], ['PUT', '/reviews/'.$review->id], ['DELETE', '/reviews/'.$review->id]];
        $before = $this->snapshot();
        foreach ($requests as [$method, $url]) {
            $this->call($method, $url, [])->assertRedirect(route('login'));
        }
        foreach ([$bookOwner, $stranger] as $attacker) {
            $this->actingAs($attacker);
            foreach (array_slice($requests, 1) as [$method, $url]) {
                foreach ([[], ['rating' => 5, 'comment' => '有効な不正更新', 'user_id' => $attacker->id, 'book_id' => $book->id]] as $input) {
                    $before = $this->snapshot();
                    $this->call($method, $url, $input)->assertForbidden();
                    $this->assertSame($before, $this->snapshot());
                }
            }
            $this->get(route('books.show', $book))->assertDontSee(route('reviews.edit', $review), false)->assertDontSee('action="'.route('reviews.destroy', $review).'"', false);
        }
        $this->actingAs($owner);
        foreach (['999999999', $review->id.'abc', $review->id.'.0', '+'.$review->id, '-'.$review->id, $review->id.'e0'] as $id) {
            foreach ([['GET', '/edit'], ['PUT', ''], ['DELETE', '']] as [$method, $suffix]) {
                $this->call($method, '/reviews/'.$id.$suffix, [])->assertNotFound();
                $this->app['auth']->forgetGuards();
                $this->call($method, '/reviews/'.$id.$suffix, [])->assertRedirect(route('login'));
                $this->actingAs($owner);
            }
        }
        $this->post('/books/999999999/reviews', [])->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->post('/books/999999999/reviews', [])->assertRedirect(route('login'));
        $this->assertSame($before, $this->snapshot());
    }

    /** T-7-10・11 / AC-7-04：レビューとそのいいねだけを物理削除し、旧URLを404にする。 */
    public function test_delete_cascades_only_target_likes_and_deleted_urls_return_404(): void
    {
        $this->actingAs($owner = User::factory()->create());
        $book = Book::factory()->create();
        $review = Review::factory()->for($owner)->for($book)->create();
        $other = Review::factory()->for($book)->create();
        $owner->likedReviews()->attach([$review->id, $other->id]);
        $owner->favoriteBooks()->attach($book);
        $genre = Genre::factory()->create();
        $book->genres()->attach($genre);
        $before = $this->snapshot();
        $this->get(route('reviews.edit', $review))->assertOk();
        $this->get(route('books.show', $book))->assertOk()->assertSee('本当に削除しますか？');
        $this->assertSame($before, $this->snapshot());
        $this->delete(route('reviews.destroy', $review))->assertRedirect(route('books.show', $book))->assertSessionHas('success', 'レビューを削除しました。');
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('review_likes', ['review_id' => $review->id]);
        $this->assertSame($this->unrelated($before, $review->id, true), $this->unrelated($this->snapshot(), $review->id, true));
        $this->get(route('books.show', $book))->assertSee('レビューを削除しました。');
        $before = $this->snapshot();
        foreach ([['GET', '/edit'], ['PUT', ''], ['DELETE', '']] as [$method, $suffix]) {
            $this->call($method, '/reviews/'.$review->id.$suffix, ['rating' => 5, 'comment' => '再生成しない'])->assertNotFound();
            $this->assertSame($before, $this->snapshot());
        }
    }

    /** T-7-12：保存前の対象消失と投稿者変更を模擬する。 */
    public function test_deleted_target_or_changed_owner_is_rechecked_before_save(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            foreach (['review', 'book', 'owner'] as $case) {
                if ($method === 'POST' && $case !== 'book') {
                    continue;
                }
                $book = Book::factory()->create();
                $review = Review::factory()->for($owner)->for($book)->create();
                $expected = null;
                $active = true;
                $change = function () use ($case, $review, $book, $other, &$expected, &$active) {
                    if (! $active) {
                        return;
                    }
                    if ($case === 'book') {
                        DB::table('books')->where('id', $book->id)->delete();
                    } elseif ($case === 'review') {
                        DB::table('reviews')->where('id', $review->id)->delete();
                    } else {
                        DB::table('reviews')->where('id', $review->id)->update(['user_id' => $other->id]);
                    }
                    $expected = $this->snapshot();
                };
                $checks = [];
                if ($method === 'DELETE') {
                    Gate::after(function ($user, $ability, $result, $arguments) use (&$checks, $change, $review, &$active) {
                        if ($active && $ability === 'delete' && $arguments[0] instanceof Review && $arguments[0]->id === $review->id) {
                            $checks[] = $result;
                            if (count($checks) === 1) {
                                $change();
                            }
                        }
                    });
                } else {
                    $this->app->afterResolving(ReviewRequest::class, $change);
                }
                $url = $method === 'POST' ? route('reviews.store', $book) : route('reviews.update', $review);
                $this->call($method, $url, ['rating' => 5, 'comment' => '保存しない'])->assertStatus($case === 'owner' ? 403 : 404);
                $this->assertSame($expected, $this->snapshot());
                if ($case === 'owner' && $method === 'DELETE') {
                    $this->assertSame([true, false], $checks);
                }
                $active = false;
                unset($active);
                // 次のケースへ模擬コールバックを持ち越さない。
                $this->app->forgetInstance(ReviewRequest::class);
            }
        }
    }

    /** 保存後に発生する想定外障害の種類を返す。 */
    public static function failureTypes(): array
    {
        return [['runtime'], ['query']];
    }

    /** T-7-12：書き込み後の障害も全体取消し、安全な500と成功フラッシュなしを確認する。 */
    #[DataProvider('failureTypes')]
    public function test_unexpected_failures_roll_back_all_changes_without_success_flash(string $kind): void
    {
        $this->actingAs($owner = User::factory()->create());
        $book = Book::factory()->create();
        $review = Review::factory()->for($owner)->for($book)->create();
        $owner->likedReviews()->attach($review);
        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            $this->app['session.store']->forget('success');
            Review::flushEventListeners();
            $event = $method === 'DELETE' ? 'deleted' : 'saved';
            $exception = $kind === 'query'
                ? new QueryException('mysql', 'SQL-secret', ['password-secret'], new \PDOException('unexpected'))
                : new \RuntimeException('SQL-secret password-secret');
            Review::$event(function () use ($exception) {
                throw $exception;
            });
            $before = $this->snapshot();
            $url = $method === 'POST' ? route('reviews.store', $book) : route('reviews.update', $review);
            $this->call($method, $url, ['rating' => 5, 'comment' => '保存取消'])->assertStatus(500)->assertDontSee('SQL-secret')->assertDontSee('password-secret')->assertSessionMissing('success');
            $this->assertSame($before, $this->snapshot());
        }
    }

    /** T-7-12の独立プロセスによる競合を列挙する。 */
    public static function concurrentCases(): array
    {
        return [['update', 'PUT'], ['review-delete', 'PUT'], ['book-delete', 'PUT'], ['book-delete', 'POST'], ['review-delete', 'DELETE'], ['book-delete', 'DELETE']];
    }

    /** T-7-12：検証後の別操作を実際に並行実行し、後勝ちと削除後404を確認する。 */
    #[DataProvider('concurrentCases')]
    public function test_independent_process_handles_concurrent_operation(string $case, string $method): void
    {
        config(['database.connections.review_race' => config('database.connections.mysql')]);
        $connection = DB::connection('review_race');
        $this->assertSame('testing', $connection->getDatabaseName());
        $user = User::factory()->make();
        $user->setConnection('review_race')->save();
        $book = Book::factory()->make(['user_id' => $user->id]);
        $book->setConnection('review_race')->save();
        $review = new Review(['user_id' => $user->id, 'book_id' => $book->id, 'rating' => 2, 'comment' => '初期値']);
        $review->setConnection('review_race')->save();
        $stream = new InputStream;
        $script = $this->childBootstrap().<<<'CHILD'
$review = App\Models\Review::findOrFail($argv[1]);
Illuminate\Support\Facades\Auth::guard()->setUser(App\Models\User::findOrFail($review->user_id));
$pause = function () {
    echo "ready
"; fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'save') { exit(3); }
};
if ($argv[2] === 'DELETE') {
    Illuminate\Support\Facades\Gate::after(function ($user, $ability, $result) use ($pause) {
        static $first = true;
        if ($first && $ability === 'delete') { $first = false; $pause(); }
    });
} else {
    $app->afterResolving(App\Http\Requests\ReviewRequest::class, $pause);
}
$url = $argv[2] === 'POST' ? '/books/'.$review->book_id.'/reviews' : '/reviews/'.$review->id;
$request = Illuminate\Http\Request::create($url, $argv[2], ['rating' => 5, 'comment' => '後保存']);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo json_encode(['status' => $response->getStatusCode()])."
";
CHILD;
        $process = new Process([PHP_BINARY, '-r', $script, (string) $review->id, $method], base_path(), ['APP_ENV' => 'testing'], $stream, 12);
        try {
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, "ready\n")), $process->getErrorOutput());
            $connection->beginTransaction();
            if ($case === 'update') {
                $connection->table('reviews')->where('id', $review->id)->update(['rating' => 1, 'comment' => '先保存']);
            } else {
                $connection->table($case === 'book-delete' ? 'books' : 'reviews')->where('id', $case === 'book-delete' ? $book->id : $review->id)->delete();
            }
            $stream->write("save\n");
            $this->waitForSql($connection, $process, $case === 'book-delete' ? 'lock in share mode' : 'for update');
            $connection->commit();
            $stream->close();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $lines = explode("\n", trim($process->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($case === 'update' ? 302 : 404, $result['status']);
            $saved = $connection->table('reviews')->where('id', $review->id)->first();
            if ($case === 'update') {
                $this->assertSame(5, $saved->rating);
                $this->assertSame('後保存', $saved->comment);
                $this->assertSame($review->user_id, $saved->user_id);
                $this->assertSame($review->book_id, $saved->book_id);
                $this->assertSame($review->getRawOriginal('created_at'), $saved->created_at);
            } else {
                $this->assertNull($saved);
                $this->assertSame(0, $connection->table('reviews')->where('book_id', $book->id)->count());
            }
        } finally {
            if ($connection->transactionLevel()) {
                $connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop(0);
            }
            $stream->close();
            $connection->table('users')->where('id', $user->id)->delete();
            DB::purge('review_race');
        }
    }

    /** テスト用子プロセスも公開testing設定だけで起動する。 */
    private function childBootstrap(): string
    {
        return <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'testing') { exit(2); }
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
CHILD;
    }

    /** 固定sleepによる順序の推測を避け、待機SQLを観測する。 */
    private function waitForSql($connection, Process $process, string $sql): void
    {
        $deadline = microtime(true) + 4;
        do {
            foreach ($connection->select('SHOW PROCESSLIST') as $thread) {
                if (str_contains(strtolower($thread->Info ?? ''), $sql)) {
                    $this->assertTrue(true);

                    return;
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline && $process->isRunning());
        $this->fail('独立プロセスの待機SQLを観測できなかった: '.$process->getOutput().$process->getErrorOutput());
    }

    /** 親共有ロックを保持する各操作を列挙する。 */
    public static function lockingOperations(): array
    {
        return [['POST'], ['PUT'], ['DELETE']];
    }

    /** T-7-12：レビューの確定保存値を観測してから、共有ロック待機中の書籍削除を許可する。 */
    #[DataProvider('lockingOperations')]
    public function test_parent_shared_lock_blocks_book_delete_until_review_operation_commits(string $method): void
    {
        config(['database.connections.review_lock' => config('database.connections.mysql')]);
        $connection = DB::connection('review_lock');
        $this->assertSame('testing', $connection->getDatabaseName());
        $user = User::factory()->make();
        $user->setConnection('review_lock')->save();
        $other = User::factory()->make();
        $other->setConnection('review_lock')->save();
        $book = Book::factory()->make(['user_id' => $user->id]);
        $book->setConnection('review_lock')->save();
        $review = new Review(['user_id' => $user->id, 'book_id' => $book->id, 'rating' => 2, 'comment' => '共有ロック前']);
        $review->created_at = $review->updated_at = '2026-01-01 00:00:00';
        $review->setConnection('review_lock')->save();
        $otherReview = new Review(['user_id' => $other->id, 'book_id' => $book->id, 'rating' => 3, 'comment' => '別レビュー']);
        $otherReview->setConnection('review_lock')->save();
        foreach ([$review, $otherReview] as $liked) {
            $connection->table('review_likes')->insert(['user_id' => $other->id, 'review_id' => $liked->id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        }
        $connection->table('favorites')->insert(['user_id' => $other->id, 'book_id' => $book->id, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        // 親テストのトランザクションとは独立し、コミット済みの保存値を読む。
        $capture = fn () => collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])->mapWithKeys(fn ($table) => [$table => $connection->table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $capture();
        $original = (array) $connection->table('reviews')->where('id', $review->id)->first();
        $saveScript = $this->childBootstrap().<<<'CHILD'
$review = App\Models\Review::findOrFail($argv[1]);
Illuminate\Support\Facades\Auth::guard()->setUser(App\Models\User::findOrFail($review->user_id));
Illuminate\Support\Carbon::setTestNow('2030-01-02 03:04:05');
$pause = function () {
    echo "locked\n"; fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'commit') { exit(3); }
};
if ($argv[2] === 'DELETE') { App\Models\Review::deleting($pause); } else { App\Models\Review::saving($pause); }
$url = $argv[2] === 'POST' ? '/books/'.$review->book_id.'/reviews' : '/reviews/'.$review->id;
$request = Illuminate\Http\Request::create($url, $argv[2], ['rating' => 5, 'comment' => '共有ロック保存', 'user_id' => $argv[3], 'book_id' => 999999999]);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
session()->start();
echo json_encode(['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'success' => session('success'), 'errors' => session('errors')?->toArray() ?? []], JSON_UNESCAPED_UNICODE)."\n";
CHILD;
        $deleteScript = $this->childBootstrap().<<<'CHILD'
$book = App\Models\Book::findOrFail($argv[1]);
Illuminate\Support\Facades\Auth::guard()->setUser(App\Models\User::findOrFail($book->user_id));
App\Models\Book::deleting(function () {
    echo "before-delete\n"; fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'delete') { exit(3); }
});
$request = Illuminate\Http\Request::create('/books/'.$book->id, 'DELETE');
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
session()->start();
echo json_encode(['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location'), 'success' => session('success'), 'errors' => session('errors')?->toArray() ?? []], JSON_UNESCAPED_UNICODE)."\n";
CHILD;
        $stream = new InputStream;
        $deleteStream = new InputStream;
        $save = new Process([PHP_BINARY, '-r', $saveScript, (string) $review->id, $method, (string) $other->id], base_path(), ['APP_ENV' => 'testing'], $stream, 12);
        $delete = new Process([PHP_BINARY, '-r', $deleteScript, (string) $book->id], base_path(), ['APP_ENV' => 'testing'], $deleteStream, 12);
        try {
            $save->start();
            $this->assertTrue($save->waitUntil(fn ($type, $output) => str_contains($output, "locked\n")), $save->getErrorOutput());
            $delete->start();
            $this->waitForSql($connection, $delete, 'for update');
            $this->assertTrue($save->isRunning());
            $this->assertTrue($delete->isRunning());
            $stream->write("commit\n");
            $stream->close();
            $this->assertSame(0, $save->wait(), $save->getErrorOutput());
            $lines = explode("\n", trim($save->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $flash = match ($method) {
                'POST' => 'レビューを登録しました。',
                'PUT' => 'レビューを更新しました。',
                'DELETE' => 'レビューを削除しました。',
            };
            $this->assertSame(['status' => 302, 'location' => route('books.show', $book), 'success' => $flash, 'errors' => []], $result);
            // 書籍ロック取得後・実削除前で止め、最終CASCADEに隠されるレビュー操作も検証する。
            $this->assertTrue($delete->waitUntil(fn ($type, $output) => str_contains($output, "before-delete\n")), $delete->getErrorOutput());
            $this->assertTrue($delete->isRunning());
            $this->assertTrue($connection->table('books')->where('id', $book->id)->exists());
            $after = $capture();
            if ($method === 'POST') {
                $created = $connection->table('reviews')->where('book_id', $book->id)->whereNotIn('id', [$review->id, $otherReview->id])->get();
                $this->assertCount(1, $created);
                $saved = (array) $created->first();
                $this->assertSame(['id' => $saved['id'], 'user_id' => $user->id, 'book_id' => $book->id, 'rating' => 5, 'comment' => '共有ロック保存', 'created_at' => '2030-01-02 03:04:05', 'updated_at' => '2030-01-02 03:04:05'], $saved);
                $this->assertSame($this->unrelated($before, $saved['id']), $this->unrelated($after, $saved['id']));
            } elseif ($method === 'PUT') {
                $this->assertSame(array_replace($original, ['rating' => 5, 'comment' => '共有ロック保存', 'updated_at' => '2030-01-02 03:04:05']), (array) $connection->table('reviews')->where('id', $review->id)->first());
                $this->assertSame($this->unrelated($before, $review->id), $this->unrelated($after, $review->id));
            } else {
                $this->assertFalse($connection->table('reviews')->where('id', $review->id)->exists());
                $this->assertFalse($connection->table('review_likes')->where('review_id', $review->id)->exists());
                $this->assertSame($this->unrelated($before, $review->id, true), $this->unrelated($after, $review->id, true));
            }
            $deleteStream->write("delete\n");
            $deleteStream->close();
            $this->assertSame(0, $delete->wait(), $delete->getErrorOutput());
            $lines = explode("\n", trim($delete->getOutput()));
            $this->assertSame(['status' => 302, 'location' => route('books.index'), 'success' => '書籍を削除しました。', 'errors' => []], json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR));
            $this->assertFalse($connection->table('books')->where('id', $book->id)->exists());
            $this->assertSame(0, $connection->table('reviews')->where('book_id', $book->id)->count());
            $this->assertSame(0, $connection->table('review_likes')->whereIn('review_id', [$review->id, $otherReview->id])->count());
            $this->assertSame(0, $connection->table('favorites')->where('book_id', $book->id)->count());
        } finally {
            foreach ([$save, $delete] as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            $stream->close();
            $deleteStream->close();
            $connection->table('users')->whereIn('id', [$user->id, $other->id])->delete();
            DB::purge('review_lock');
        }
    }
}

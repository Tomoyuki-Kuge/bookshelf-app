<?php

/** 公開ランキングの正確な順位・表示とWeb操作後の再集計を検証する。 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithRenderedHtml;
use Tests\TestCase;

class RankingTest extends TestCase
{
    use InteractsWithRenderedHtml;

    /** testingの空データを確認し、各ケースの変更をトランザクション内に限定する。 */
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('testing', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame(0, DB::table('books')->count(), '既存書籍があるため削除せず検証を停止する。');
        $this->assertSame(0, DB::table('reviews')->count(), '既存レビューがあるため削除せず検証を停止する。');
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->withoutVite();
    }

    /** 対象冊数と閲覧者の境界を網羅する。 */
    public static function boundaries(): array
    {
        $cases = [];
        foreach ([0, 1, 10, 11] as $count) {
            foreach ([false, true] as $authenticated) {
                $cases[$count.'-'.($authenticated ? 'member' : 'guest')] = [$count, $authenticated];
            }
        }

        return $cases;
    }

    /** AC-10-01・03 / T-10-1・4・5：対象選別、上限、連番とカードの全表示を照合する。 */
    #[DataProvider('boundaries')]
    public function test_public_boundaries_and_each_rendered_card(int $count, bool $authenticated): void
    {
        if ($authenticated) {
            $this->actingAs(User::factory()->create());
        }
        $this->assertRanking([])->assertSee('まだレビューが投稿された書籍がありません。');
        Book::factory()->create();
        $this->assertRanking([])->assertSee('まだレビューが投稿された書籍がありません。');
        $expected = [];
        $expectedBooks = [];
        foreach (range(1, max(1, $count)) as $index) {
            if ($index > $count) {
                break;
            }
            $attributes = ['title' => '順位用書籍'.$index, 'author' => '著者'.$index, 'image_url' => $index % 2 ? 'https://example.com/cover'.$index.'.jpg' : null];
            $book = Book::factory()->create($attributes);
            $expectedBooks[$book->id] = ['id' => $book->id] + $attributes;
            $this->reviews($book, [3, 4, 4]);
            $expected[] = $book->id;
            // レビューなしを先頭・途中・境界付近にも混在させる。
            Book::factory()->create();
        }
        $before = $this->snapshot();
        $response = $this->assertRanking(array_slice($expected, 0, 10));
        $xpath = $this->createDomXPath($response);
        foreach (array_slice($expected, 0, 10) as $index => $id) {
            $source = $expectedBooks[$id];
            $book = $response['rankedBooks'][$index];
            $this->assertSame($source, ['id' => $book->id, 'title' => $book->title, 'author' => $book->author, 'image_url' => $book->image_url], '作成元の書籍属性を返却する');
            $card = $xpath->query('//main//a[h3 or .//h3]')[$index];
            $this->assertSame(route('books.show', $id), $card->getAttribute('href'));
            $this->assertSame((string) ($index + 1), trim($xpath->query('./div/div[1]', $card)[0]->textContent));
            $this->assertSame($source['title'], trim($xpath->query('.//h3', $card)[0]->textContent));
            $this->assertSame($source['author'], trim($xpath->query('.//p', $card)[0]->textContent));
            $this->assertSame('3.67', trim($xpath->query('.//span[contains(@class,"text-sm")]', $card)[0]->textContent));
            $this->assertSame('(3件のレビュー)', trim($xpath->query('.//span[contains(@class,"text-xs") and contains(@class,"ml-2")]', $card)[0]->textContent));
            $this->assertSame('3.7', trim($xpath->query('.//div[contains(@class,"text-2xl")]', $card)[0]->textContent));
            $this->assertCount(4, $xpath->query('.//span[contains(@class,"text-yellow-400")]', $card));
            $this->assertCount(1, $xpath->query('.//span[contains(@class,"text-gray-300")]', $card));
            if ($source['image_url'] !== null) {
                $image = $xpath->query('.//img', $card)[0];
                $this->assertSame($source['image_url'], $image->getAttribute('src'));
                $this->assertSame($source['title'], $image->getAttribute('alt'));
            } else {
                $this->assertCount(0, $xpath->query('.//img', $card));
                $this->assertStringContainsString('No Image', $card->textContent);
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    /** AC-10-02 / T-10-2・3：DBのAVGでも消える差と、真の同平均の優先順を照合する。 */
    public function test_close_averages_and_equal_average_tiebreaks(): void
    {
        $lower = Book::factory()->create();
        $higher = Book::factory()->create();
        $this->reviews($lower, array_merge(array_fill(0, 201, 4), [5]));
        $this->reviews($higher, array_merge(array_fill(0, 200, 4), [5]));
        $smallId = Book::factory()->create();
        $largeId = Book::factory()->create();
        $moreReviews = Book::factory()->create();
        $this->reviews($smallId, [3, 5]);
        $this->reviews($largeId, [4, 4]);
        $this->reviews($moreReviews, [3, 4, 5]);
        $response = $this->assertRanking([$higher->id, $lower->id, $moreReviews->id, $smallId->id, $largeId->id]);
        $xpath = $this->createDomXPath($response);
        foreach ([0, 1] as $index) {
            $card = $xpath->query('//main//a[.//h3]')[$index];
            $this->assertSame('4.00', trim($xpath->query('.//span[contains(@class,"text-sm")]', $card)[0]->textContent));
            $this->assertSame('4.0', trim($xpath->query('.//div[contains(@class,"text-2xl")]', $card)[0]->textContent));
        }
        $top = [];
        foreach (range(1, 9) as $index) {
            $book = Book::factory()->create();
            $this->reviews($book, [5]);
            $top[] = $book->id;
        }
        $this->assertRanking([...$top, $higher->id]);
    }

    /** T-10-6：投稿・更新・削除後の値と、無関係な書籍の保存値を検証する。 */
    public function test_web_review_changes_recalculate_without_changing_other_books(): void
    {
        $user = User::factory()->create();
        $target = Book::factory()->for($user)->create();
        $other = Book::factory()->create();
        $this->reviews($other, [4]);
        $original = Review::factory()->for($target)->for($user)->create(['rating' => 3]);
        $otherBefore = [$other->fresh()->getAttributes(), $other->reviews()->get()->toArray()];
        $this->assertRanking([$other->id, $target->id]);
        $this->actingAs($user)->post(route('reviews.store', $target), ['rating' => 5, 'comment' => '新しい評価'])->assertRedirect(route('books.show', $target));
        $review = $target->reviews()->whereKeyNot($original->id)->sole();
        $response = $this->assertRanking([$target->id, $other->id]);
        $this->assertSame(4.0, $response['rankedBooks'][0]->reviews_avg_rating);
        $this->assertSame(2, (int) $response['rankedBooks'][0]->reviews_count);
        $this->put(route('reviews.update', $review), ['rating' => 1, 'comment' => '更新評価'])->assertRedirect(route('books.show', $target));
        $response = $this->assertRanking([$other->id, $target->id]);
        $this->assertSame(2.0, $response['rankedBooks'][1]->reviews_avg_rating);
        $this->assertSame(2, (int) $response['rankedBooks'][1]->reviews_count);
        $this->delete(route('reviews.destroy', $review))->assertRedirect(route('books.show', $target));
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $response = $this->assertRanking([$other->id, $target->id]);
        $this->assertSame(3.0, $response['rankedBooks'][1]->reviews_avg_rating);
        $this->assertSame(1, (int) $response['rankedBooks'][1]->reviews_count);
        $this->delete(route('reviews.destroy', $original))->assertRedirect(route('books.show', $target));
        $this->assertDatabaseMissing('reviews', ['id' => $original->id]);
        $this->assertRanking([$other->id]);
        $this->assertSame($otherBefore, [$other->fresh()->getAttributes(), $other->reviews()->get()->toArray()]);
    }

    /** 最後のレビューと書籍本体の削除を別ケースで検証する。 */
    public static function deletions(): array
    {
        return ['last-review' => [false], 'book' => [true]];
    }

    /** T-10-6：上位の除外時に11位が繰り上がり、対象外データは保持される。 */
    #[DataProvider('deletions')]
    public function test_web_deletion_promotes_eleventh_book(bool $deleteBook): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->for($user)->count(11)->create();
        foreach ($books as $book) {
            Review::factory()->for($book)->for($user)->create(['rating' => 4]);
        }
        $ids = $books->modelKeys();
        $this->assertRanking(array_slice($ids, 0, 10));
        $target = $books[0];
        $review = $target->reviews()->sole();
        $before = $this->snapshot();
        $this->actingAs($user)->delete($deleteBook ? route('books.destroy', $target) : route('reviews.destroy', $review))->assertRedirect();
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        if ($deleteBook) {
            $this->assertDatabaseMissing('books', ['id' => $target->id]);
        } else {
            $this->assertDatabaseHas('books', ['id' => $target->id]);
        }
        $this->assertRanking(array_slice($ids, 1));
        $after = $this->snapshot();
        foreach (['books', 'reviews'] as $table) {
            $excluded = $table === 'books' ? ($deleteBook ? [$target->id] : []) : [$review->id];
            $before[$table] = array_values(array_filter($before[$table], fn ($row) => ! in_array($row['id'], $excluded, true)));
        }
        $this->assertSame($before, $after);
    }

    /** 集計は単一SELECTで情報を揃え、障害は空表示に変換しない。 */
    public function test_single_aggregate_select_and_safe_failure(): void
    {
        $book = Book::factory()->create();
        $this->reviews($book, [4]);
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            if (preg_match('/^select.*(?:`books`|`reviews`)/i', $query->sql)) {
                $queries[] = $query->sql;
            }
        });
        $this->assertRanking([$book->id]);
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('SUM(reviews.rating)', $queries[0]);
        $this->assertStringContainsString('COUNT(reviews.id)', $queries[0]);
        $before = $this->snapshot();
        Log::spy();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed) {
            if ($armed && str_contains($query->sql, 'SUM(reviews.rating)')) {
                $armed = false;
                throw new QueryException('mysql', 'SQL-secret', ['password-secret'], new \PDOException('unexpected'));
            }
        });
        $this->get(route('ranking.index'))->assertStatus(500)->assertDontSee('SQL-secret')->assertDontSee('password-secret')->assertDontSee('Stack trace')->assertDontSee('まだレビューが投稿された書籍がありません。');
        $this->assertFalse($armed);
        $this->assertSame($before, $this->snapshot());
        Log::shouldHaveReceived('error')->once()->with('アプリケーションで例外が発生しました。', \Mockery::on(fn ($context) => array_keys($context) === ['occurred_at', 'route', 'exception_type'] && $context['route'] === 'ranking' && $context['exception_type'] === QueryException::class));
    }

    /** 固定期待IDと描画された詳細リンクのDOM順・順位を照合する。 */
    private function assertRanking(array $ids)
    {
        $response = $this->get(route('ranking.index'))->assertOk();
        $this->assertSame($ids, $response['rankedBooks']->pluck('id')->all());
        $this->assertSame(array_keys($ids), $response['rankedBooks']->keys()->all());
        $xpath = $this->createDomXPath($response);
        $cards = $xpath->query('//main//a[.//h3]');
        $this->assertCount(count($ids), $cards);
        foreach ($ids as $index => $id) {
            $this->assertSame(route('books.show', $id), $cards[$index]->getAttribute('href'));
            $this->assertSame((string) ($index + 1), trim($xpath->query('./div/div[1]', $cards[$index])[0]->textContent));
        }

        return $response;
    }

    /** 大量の同評価レビューも関連利用者を一度だけ用意して投入する。 */
    private function reviews(Book $book, array $ratings): void
    {
        $user = User::factory()->create();
        DB::table('reviews')->insert(array_map(fn ($rating) => ['book_id' => $book->id, 'user_id' => $user->id, 'rating' => $rating, 'comment' => '順位確認', 'created_at' => '2030-01-01 00:00:00', 'updated_at' => '2030-01-01 00:00:00'], $ratings));
    }

    /** 全7業務表の全保存値を取得する。 */
    private function snapshot(): array
    {
        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
    }
}

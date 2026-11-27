<?php

/** 基本機能をWebと公開APIの境界をまたいで通し、表示・認可・集計の整合を検証する。 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithRenderedHtml;
use Tests\TestCase;

class BasicWorkflowTest extends TestCase
{
    use InteractsWithRenderedHtml;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::beginTransaction();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /** 拒否の直前と直後で日時・関連IDを含む全保存値を比較する。 */
    private function snapshot(): array
    {
        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    private function input(Genre $genre, array $changes = []): array
    {
        return array_replace(['title' => '横断確認の書籍', 'author' => '横断著者', 'isbn' => '0123456789012', 'published_date' => '2026-01-02', 'description' => '横断説明', 'image_url' => 'https://example.com/book.png', 'genres' => [$genre->id]], $changes);
    }

    /** T-12-3〜5：会員登録から公開API、権限移動、レビュー再集計、関連削除まで通す。 */
    public function test_registration_to_web_and_api_crud_keeps_permissions_and_aggregates_consistent(): void
    {
        $email = 'workflow-'.bin2hex(random_bytes(8)).'@example.com';
        $this->post('/register', ['name' => '横断利用者', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect('/');
        $owner = User::where('email', $email)->firstOrFail();
        $other = User::factory()->create();
        $unrelated = Book::factory()->for($other)->create();
        $unrelatedGenre = Genre::factory()->create();
        $unrelated->genres()->attach($unrelatedGenre);
        $unrelatedReview = Review::factory()->for($unrelated)->for($other)->create();
        $other->favoriteBooks()->attach($unrelated);
        $owner->likedReviews()->attach($unrelatedReview);
        $name = '横断ジャンル'.bin2hex(random_bytes(6));
        $this->post('/genres', ['name' => $name])->assertRedirect('/genres');
        $genre = Genre::where('name', $name)->firstOrFail();
        $input = $this->input($genre);
        $this->post('/books', $input + ['user_id' => $other->id])->assertRedirect();
        $book = Book::where('isbn', $input['isbn'])->firstOrFail();
        $this->assertSame($owner->id, $book->user_id);
        $this->post('/books/'.$book->id.'/reviews', ['rating' => 2, 'comment' => '本人レビュー', 'user_id' => $other->id])->assertRedirect('/books/'.$book->id);
        $review = $book->reviews()->firstOrFail();
        $this->assertSame($owner->id, $review->user_id);
        $this->post('/reviews/'.$review->id.'/like')->assertRedirect('/books/'.$book->id);
        $this->post('/books/'.$book->id.'/favorites')->assertRedirect('/books/'.$book->id);
        $this->assertSame([$book->id], $this->get('/favorites')->assertOk()->viewData('books')->modelKeys());
        $html = $this->createDomXPath($this->get('/books/'.$book->id)->assertOk());
        $this->assertCount(1, $html->query('//form[@action="'.route('favorites.toggle', $book).'"]/button[@title="お気に入りから削除"]'));
        $this->assertCount(1, $html->query('//form[@action="'.route('reviews.like', $review).'"]/button[contains(normalize-space(.),"いいね済み (1)")]'));
        $this->assertDisplaysCurrentState($book, $genre, [2], 1);

        $this->actingAs($other->fresh());
        $this->get('/books/'.$book->id)->assertOk()->assertDontSee('href="'.route('books.edit', $book).'"', false)->assertDontSee('href="'.route('reviews.edit', $review).'"', false);
        $html = $this->createDomXPath($this->get('/books/'.$book->id)->assertOk());
        $this->assertCount(1, $html->query('//form[@action="'.route('favorites.toggle', $book).'"]/button[@title="お気に入りに追加"]'));
        $this->post('/books/'.$book->id.'/reviews', ['rating' => 4, 'comment' => '別利用者レビュー'])->assertRedirect();
        $second = $book->reviews()->where('user_id', $other->id)->firstOrFail();
        $this->assertDisplaysCurrentState($book, $genre, [2, 4], 1);
        $this->put('/reviews/'.$second->id, ['rating' => 5, 'comment' => '更新後レビュー'])->assertRedirect();
        $this->assertDisplaysCurrentState($book, $genre, [2, 5], 1);
        $this->delete('/reviews/'.$second->id)->assertRedirect();
        $this->assertDisplaysCurrentState($book, $genre, [2], 1);

        $this->post('/logout')->assertRedirect('/login');
        $this->get('/books/'.$book->id)->assertOk()->assertDontSee('action="'.route('reviews.store', $book).'"', false)->assertSee('ログイン');
        $this->getJson('/api/v1/books/'.$book->id)->assertOk()->assertJsonPath('data.title', $input['title'])->assertJsonPath('data.review_count', 1)->assertJsonPath('data.reviews.0.comment', '本人レビュー');
        $updated = $this->input($genre, ['user_id' => $other->id, 'title' => 'APIで更新した書籍']);
        $this->putJson('/api/v1/books/'.$book->id, $updated)->assertOk()->assertJsonPath('data.title', $updated['title']);
        $this->assertSame($other->id, $book->fresh()->user_id);
        $this->assertSame($updated['title'], $book->fresh()->title);
        $this->actingAs($owner->fresh());
        foreach ([['GET', '/books/'.$book->id.'/edit'], ['PUT', '/books/'.$book->id], ['DELETE', '/books/'.$book->id]] as [$method, $url]) {
            $before = $this->snapshot();
            $this->call($method, $url, $input)->assertForbidden();
            $this->assertSame($before, $this->snapshot());
        }
        $this->actingAs($other->fresh())->get('/books/'.$book->id.'/edit')->assertOk();
        $this->put('/books/'.$book->id, $this->input($genre, ['title' => '新登録者の更新']))->assertRedirect('/books/'.$book->id);
        $this->get('/books/'.$book->id)->assertOk()->assertSee('新登録者の更新')->assertSee('href="'.route('books.edit', $book).'"', false);
        $this->post('/logout');
        $beforeDelete = $this->snapshot();
        $this->deleteJson('/api/v1/books/'.$book->id)->assertNoContent();
        foreach ($beforeDelete as $table => $rows) {
            $expected = array_values(array_filter(json_decode($rows, true), fn ($row) => ! match ($table) {
                'books' => $row['id'] === $book->id,
                'reviews', 'favorites', 'book_genre' => $row['book_id'] === $book->id,
                'review_likes' => $row['review_id'] === $review->id,
                default => false,
            }));
            $this->assertSame($expected, json_decode($this->snapshot()[$table], true), $table.'の対象外を維持');
        }
        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        foreach (['book_genre', 'reviews', 'favorites'] as $table) {
            $this->assertDatabaseMissing($table, ['book_id' => $book->id]);
        }
        $this->assertDatabaseMissing('review_likes', ['review_id' => $review->id]);
        $this->assertDatabaseHas('genres', ['id' => $genre->id]);
        $this->assertNotContains($book->id, $this->get('/')->assertOk()->viewData('books')->modelKeys());
        $this->assertNotContains($book->id, $this->get('/ranking')->assertOk()->viewData('rankedBooks')->modelKeys());
        $this->actingAs($owner->fresh());
        $this->assertSame([], $this->get('/favorites')->assertOk()->viewData('books')->modelKeys());
        $this->assertSame(0, $this->get('/genres')->assertOk()->viewData('genres')->find($genre->id)->books_count);
        $this->post('/logout');

        $created = $this->postJson('/api/v1/books', $input + ['user_id' => $owner->id])->assertCreated()->json('data.id');
        $this->get('/books/'.$created)->assertOk()->assertSee($input['title'])->assertSee($genre->name);
        $this->actingAs($owner->fresh())->delete('/books/'.$created)->assertRedirect('/');
        $before = $this->snapshot();
        $this->getJson('/api/v1/books/'.$created)->assertNotFound()->assertExactJson(['error' => '書籍が見つかりませんでした。']);
        $this->assertSame($before, $this->snapshot());
    }

    /** 一覧・詳細・API・ジャンル・ランキングを同じ保存済み評価から照合する。 */
    private function assertDisplaysCurrentState(Book $book, Genre $genre, array $ratings, int $likes): void
    {
        $average = array_sum($ratings) / count($ratings);
        $listed = $this->get('/')->assertOk()->viewData('books')->find($book->id);
        $this->assertEqualsWithDelta($average, (float) $listed->reviews_avg_rating, 0.0001);
        $detail = $this->get('/books/'.$book->id)->assertOk()->viewData('book');
        $this->assertCount(count($ratings), $detail->reviews);
        $this->assertSame($likes, $detail->reviews->sum(fn ($review) => $review->likedByUsers->count()));
        $api = $this->getJson('/api/v1/books/'.$book->id)->assertOk();
        $this->assertEquals(round($average, 1), $api->json('data.average_rating'));
        $api->assertJsonPath('data.review_count', count($ratings));
        $ranked = $this->get('/ranking')->assertOk()->viewData('rankedBooks')->find($book->id);
        $this->assertSame(count($ratings), (int) $ranked->reviews_count);
        $this->assertEqualsWithDelta($average, $ranked->reviews_avg_rating, 0.0001);
        $this->assertSame(1, $this->get('/genres')->assertOk()->viewData('genres')->find($genre->id)->books_count);
    }

    /** T-12-6：各保護入口・他者の各入口・削除済み・入力不正で全業務保存値を維持する。 */
    public function test_rejected_cross_feature_entries_preserve_all_seven_tables(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $genre = Genre::factory()->create();
        $book = Book::factory()->for($owner)->create();
        $book->genres()->attach($genre);
        $review = Review::factory()->for($book)->for($owner)->create();
        $other->favoriteBooks()->attach($book);
        $other->likedReviews()->attach($review);
        $input = $this->input($genre);
        $entries = [['GET', '/books/create'], ['POST', '/books'], ['GET', '/books/'.$book->id.'/edit'], ['PUT', '/books/'.$book->id], ['DELETE', '/books/'.$book->id], ['GET', '/genres'], ['GET', '/genres/create'], ['POST', '/genres'], ['GET', '/genres/'.$genre->id], ['GET', '/genres/'.$genre->id.'/edit'], ['PUT', '/genres/'.$genre->id], ['DELETE', '/genres/'.$genre->id], ['POST', '/books/'.$book->id.'/reviews'], ['GET', '/reviews/'.$review->id.'/edit'], ['PUT', '/reviews/'.$review->id], ['DELETE', '/reviews/'.$review->id], ['POST', '/reviews/'.$review->id.'/like'], ['POST', '/books/'.$book->id.'/favorites'], ['GET', '/favorites'], ['POST', '/logout']];
        foreach ($entries as [$method, $url]) {
            $before = $this->snapshot();
            $this->call($method, $url, $input)->assertRedirect('/login');
            $this->assertSame($before, $this->snapshot(), $method.' '.$url);
        }
        $this->actingAs($other);
        foreach (['books/'.$book->id, 'reviews/'.$review->id] as $path) {
            foreach ([['GET', '/edit'], ['PUT', ''], ['DELETE', '']] as [$method, $suffix]) {
                $before = $this->snapshot();
                $this->call($method, '/'.$path.$suffix, $input + ['rating' => 5, 'comment' => '拒否対象の有効入力'])->assertForbidden();
                $this->assertSame($before, $this->snapshot());
            }
        }
        $this->actingAs($owner);
        foreach ([['PUT', '/books/'.$book->id, ['title' => '']], ['PUT', '/reviews/'.$review->id, ['rating' => 0, 'comment' => '']], ['POST', '/genres', ['name' => '']]] as [$method, $url, $invalid]) {
            $before = $this->snapshot();
            $this->call($method, $url, $invalid)->assertRedirect()->assertSessionHasErrors();
            $this->assertSame($before, $this->snapshot());
        }
        $this->delete('/books/'.$book->id)->assertRedirect('/');
        foreach ([['GET', '/books/'.$book->id], ['GET', '/books/'.$book->id.'/edit'], ['PUT', '/books/'.$book->id], ['DELETE', '/books/'.$book->id], ['POST', '/books/'.$book->id.'/reviews'], ['POST', '/books/'.$book->id.'/favorites'], ['GET', '/reviews/'.$review->id.'/edit'], ['PUT', '/reviews/'.$review->id], ['DELETE', '/reviews/'.$review->id], ['POST', '/reviews/'.$review->id.'/like']] as [$method, $url]) {
            $before = $this->snapshot();
            $this->call($method, $url, $input)->assertNotFound();
            $this->assertSame($before, $this->snapshot());
        }
    }
}

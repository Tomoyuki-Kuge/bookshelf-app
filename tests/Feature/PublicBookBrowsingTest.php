<?php

/** 公開の書籍一覧・詳細と利用者ごとの表示をtesting専用DBで検証する。 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicBookBrowsingTest extends TestCase
{
    /** testingを確認して通常migrationとトランザクションで独立したデータを用意する。 */
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('testing', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        $this->assertSame(0, Book::count(), '既存のtesting書籍を削除せず停止します。');
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->withoutVite();
    }

    /** 0・1・10・11件のページ境界を用意する。 */
    public static function bookCounts(): array
    {
        return [[0], [1], [10], [11]];
    }

    /** AC-4-01・04 / T-4-1・3・11：全ページの表示対象とリンク、空表示を確認する。 */
    #[DataProvider('bookCounts')]
    public function test_t_4_1_3_11_guest_index_paginates_exact_books(int $count): void
    {
        $books = Book::factory()->count($count)->create(['created_at' => '2026-01-01 00:00:00']);
        $expected = $books->reverse()->pluck('id')->values()->all();
        $pages = array_chunk($expected, 10) ?: [[]];
        foreach ($pages as $index => $ids) {
            $response = $this->get('/?page='.($index + 1))->assertOk()->assertViewIs('books.index');
            $page = $response['books'];
            $this->assertSame($ids, $page->pluck('id')->all());
            $this->assertSame($count, $page->total());
            $this->assertSame(count($ids), $page->count());
            $this->assertSame(10, $page->perPage());
            $xpath = $this->xpath($response);
            $links = $xpath->query('//a[contains(@class,"cursor-pointer")]/@href');
            $this->assertSame(array_map(fn ($id) => route('books.show', $id), $ids),
                array_map(fn ($node) => $node->nodeValue, iterator_to_array($links)));
            $this->assertSame($count === 0, str_contains($response->getContent(), '書籍が登録されていません。'));
            $response->assertSee('href="'.route('books.create').'"', false);
            if ($count > 10) {
                $this->assertGreaterThan(0, $xpath->query('//a[contains(@href,"?page='.($index === 0 ? 2 : 1).'")]')->length);
            }
        }
    }

    /** AC-4-01 / T-4-2：登録日時、同日時ID順を保ち、更新日時には影響されない。 */
    public function test_t_4_2_index_order_is_unchanged_by_updates(): void
    {
        $older = Book::factory()->create(['created_at' => '2025-01-01 00:00:00']);
        $newer = Book::factory()->count(2)->create(['created_at' => '2026-01-01 00:00:00']);
        $expected = [$newer[1]->id, $newer[0]->id, $older->id];
        $this->assertSame($expected, $this->get('/')->assertOk()['books']->pluck('id')->all());
        $older->update(['title' => '更新した古い書籍', 'updated_at' => '2027-01-01 00:00:00']);
        $this->assertSame($expected, $this->get('/')->assertOk()['books']->pluck('id')->all());
        $this->actingAs($older->user);
        $this->assertSame($expected, $this->get('/')->assertOk()['books']->pluck('id')->all());
    }

    /** AC-4-01 / T-4-4：カードの全ジャンル、画像、altと代替表示を確認する。 */
    public function test_t_4_4_index_renders_book_information_and_image_fallback(): void
    {
        $book = Book::factory()->create(['title' => '公開一覧の書籍', 'author' => '一覧の著者', 'image_url' => 'https://example.com/cover.jpg']);
        $genres = Genre::factory()->count(2)->create();
        $book->genres()->attach($genres);
        $empty = Book::factory()->create(['title' => '画像のない書籍']);
        $response = $this->get('/')->assertOk()->assertSee($book->title)->assertSee($book->author);
        foreach ($genres as $genre) {
            $response->assertSee($genre->name);
        }
        $xpath = $this->xpath($response);
        $card = $this->card($xpath, $book);
        $this->assertSame($book->title, $xpath->query('.//img', $card)->item(0)->getAttribute('alt'));
        $this->assertSame($book->image_url, $xpath->query('.//img', $card)->item(0)->getAttribute('src'));
        $emptyCard = $this->card($xpath, $empty);
        $this->assertStringContainsString('画像なし', $emptyCard->textContent);
        $this->assertSame(0, $xpath->query('.//img', $emptyCard)->length);
    }

    /** 平均値の小数丸めと星の四捨五入の前後を用意する。 */
    public static function ratings(): array
    {
        return [[[3, 3, 4], '3.3', '★★★☆☆'], [[3, 4], '3.5', '★★★★☆'], [[3, 4, 4], '3.7', '★★★★☆']];
    }

    /** AC-4-03 / T-4-5：平均と星を表示し、未評価にはどちらも表示しない。 */
    #[DataProvider('ratings')]
    public function test_t_4_5_index_rounds_average_and_omits_unrated_books(array $ratings, string $average, string $stars): void
    {
        $book = Book::factory()->create();
        $unrated = Book::factory()->create();
        foreach ($ratings as $rating) {
            Review::factory()->for($book)->create(['rating' => $rating]);
        }
        $xpath = $this->xpath($this->get('/')->assertOk());
        $card = $this->card($xpath, $book);
        $this->assertStringContainsString('('.$average.')', $card->textContent);
        $this->assertSame($stars, preg_replace('/\s+/u', '', $xpath->query('.//span[contains(@class,"text-yellow-500")]', $card)->item(0)->textContent));
        $unratedCard = $this->card($xpath, $unrated);
        $this->assertSame(0, $xpath->query('.//span[contains(@class,"text-yellow-500")]', $unratedCard)->length);
        $this->assertStringNotContainsString('(0.0)', $unratedCard->textContent);
    }

    /** AC-4-02 / T-4-6・12：全書籍情報とYYYY-MM-DD文字列を既存Bladeへ渡す。 */
    public function test_t_4_6_12_detail_shows_information_and_string_publication_date(): void
    {
        $book = Book::factory()->create(['title' => '公開詳細の書籍', 'author' => '詳細の著者', 'published_date' => '2024-02-29', 'image_url' => 'https://example.com/detail.jpg', 'description' => '詳細の説明文']);
        $genres = Genre::factory()->count(2)->create();
        $book->genres()->attach($genres);
        $response = $this->get(route('books.show', $book))->assertOk()->assertViewIs('books.show');
        foreach ([$book->title, $book->author, $book->isbn, '2024-02-29', $book->description, ...$genres->pluck('name')->all()] as $text) {
            $response->assertSee($text);
        }
        $this->assertSame('2024-02-29', $response['book']->published_date);
        $xpath = $this->xpath($response);
        $image = $xpath->query('//img[@src="https://example.com/detail.jpg"]')->item(0);
        $this->assertNotNull($image);
        $this->assertSame($book->title, $image->getAttribute('alt'));
    }

    /** AC-4-02 / T-4-7・8：12件を日時順に全件表示し、別書籍を混在させない。 */
    public function test_t_4_7_detail_shows_all_reviews_in_date_order_with_likes(): void
    {
        $book = Book::factory()->create();
        $authorNames = ['レビュー投稿者甲', 'レビュー投稿者乙', 'レビュー投稿者丙'];
        $authors = array_map(fn ($name) => User::factory()->create(['name' => $name]), $authorNames);
        $likers = User::factory()->count(2)->create();
        $expected = [];
        // 作成順を日時の昇順・降順のどちらとも異ならせ、ID順への取り違えを検出する。
        foreach ([4, 11, 2, 8, 12, 5, 1, 10, 6, 3, 9, 7] as $day) {
            $authorIndex = $day % count($authors);
            $fixture = [
                'author' => $authorNames[$authorIndex],
                'rating' => $day % 5 + 1,
                'comment' => '対象レビュー番号'.$day.'です。',
                'created_at' => sprintf('2026-02-%02d 12:00:00', $day),
                'date' => sprintf('2026/02/%02d', $day),
                'likes' => $day === 11 ? 2 : 0,
            ];
            $review = Review::factory()->for($book)->for($authors[$authorIndex])->create([
                'rating' => $fixture['rating'], 'comment' => $fixture['comment'], 'created_at' => $fixture['created_at'],
            ]);
            if ($fixture['likes'] === 2) {
                $review->likedByUsers()->attach($likers);
            }
            $expected[] = $fixture + ['id' => $review->id];
        }
        // DBの取得順や取得した関連値を使わず、準備時の定義から期待列を組み立てる。
        usort($expected, fn ($left, $right) => strcmp($right['created_at'], $left['created_at']));
        $foreign = Review::factory()->create(['comment' => '別書籍専用のレビュー', 'created_at' => '2027-01-01 00:00:00']);
        $response = $this->get(route('books.show', $book))->assertOk();
        $this->assertSame(array_column($expected, 'id'), $response['book']->reviews->pluck('id')->all());
        $response->assertSeeInOrder(array_column($expected, 'comment'))->assertDontSee($foreign->comment);
        $xpath = $this->xpath($response);
        $rows = $xpath->query('//div[contains(@class,"space-y-4")]/div');
        $this->assertSame(12, $rows->length);
        foreach ($expected as $index => $fixture) {
            $row = $rows->item($index);
            $this->assertSame($fixture['author'], trim($xpath->query('.//span[@class="font-semibold"]', $row)->item(0)->textContent));
            $this->assertSame(str_repeat('★', $fixture['rating']).str_repeat('☆', 5 - $fixture['rating']),
                trim($xpath->query('.//span[contains(@class,"text-yellow-500")]', $row)->item(0)->textContent));
            $this->assertSame($fixture['comment'], trim($xpath->query('.//p[@class="text-gray-700"]', $row)->item(0)->textContent));
            $this->assertSame($fixture['date'], trim($xpath->query('.//span[@class="text-sm text-gray-500"]', $row)->item(0)->textContent));
            $this->assertSame('いいね ('.$fixture['likes'].')',
                trim(preg_replace('/\s+/u', ' ', $xpath->query('.//a[@href="'.route('login').'"]', $row)->item(0)->textContent)));
        }
        $this->assertSame(0, $xpath->query('//nav[@role="navigation"]')->length);
    }

    /** AC-4-04 / T-4-8：未設定の画像・説明、レビュー0件の詳細を確認する。 */
    public function test_t_4_8_detail_handles_empty_optional_fields_and_reviews(): void
    {
        $book = Book::factory()->create(['image_url' => null, 'description' => null]);
        $this->get(route('books.show', $book))->assertOk()->assertSee('画像なし')->assertSee('まだレビューはありません。')->assertDontSee('説明:');
    }

    /** T-4-9：ゲストにはログイン導線だけを表示し、投稿・本人操作を表示しない。 */
    public function test_t_4_9_guest_detail_has_login_links_without_operation_forms(): void
    {
        $book = Book::factory()->create();
        $review = Review::factory()->for($book)->create();
        $response = $this->get(route('books.show', $book))->assertOk()
            ->assertSee('レビューを投稿するには')->assertSee('お気に入りに追加するにはログインしてください')->assertSee('いいね (0)');
        $xpath = $this->xpath($response);
        $this->assertGreaterThanOrEqual(3, $xpath->query('//a[@href="'.route('login').'"]')->length);
        foreach (['reviews.store' => $book, 'favorites.toggle' => $book, 'reviews.like' => $review, 'books.destroy' => $book, 'reviews.destroy' => $review] as $route => $model) {
            $this->assertSame(0, $xpath->query('//form[@action="'.route($route, $model).'"]')->length);
        }
        foreach (['books.edit' => $book, 'reviews.edit' => $review] as $route => $model) {
            $response->assertDontSee('href="'.route($route, $model).'"', false);
        }
    }

    /** 書籍作成者、別の投稿者、無関係な利用者を用意する。 */
    public static function viewers(): array
    {
        return [['owner'], ['reviewer'], ['stranger']];
    }

    /** T-4-1・10：本人Policy、利用者自身のお気に入り・いいね、空の投稿欄を確認する。 */
    #[DataProvider('viewers')]
    public function test_t_4_10_authenticated_detail_matches_ownership_and_user_state(string $role): void
    {
        $users = ['owner' => User::factory()->create(), 'reviewer' => User::factory()->create(), 'stranger' => User::factory()->create()];
        $book = Book::factory()->for($users['owner'])->create();
        $ownReview = Review::factory()->for($book)->for($users['owner'])->create();
        $otherReview = Review::factory()->for($book)->for($users['reviewer'])->create();
        $users['owner']->favoriteBooks()->attach($book);
        $users['reviewer']->favoriteBooks()->attach(Book::factory()->create());
        $users['owner']->likedReviews()->attach($ownReview);
        $users['reviewer']->likedReviews()->attach([$ownReview->id, $otherReview->id]);
        $response = $this->actingAs($users[$role])->get(route('books.show', $book))->assertOk();
        $xpath = $this->xpath($response);
        $this->assertSame($role === 'owner' ? 1 : 0, $xpath->query('//a[@href="'.route('books.edit', $book).'"]')->length);
        $this->assertSame($role === 'owner' ? 1 : 0, $xpath->query('//form[@action="'.route('books.destroy', $book).'"]')->length);
        foreach ([$ownReview, $otherReview] as $review) {
            $isAuthor = $review->user_id === $users[$role]->id;
            $this->assertSame($isAuthor ? 1 : 0, $xpath->query('//a[@href="'.route('reviews.edit', $review).'"]')->length);
            $this->assertSame($isAuthor ? 1 : 0, $xpath->query('//form[@action="'.route('reviews.destroy', $review).'"]')->length);
            $likeForm = $xpath->query('//form[@action="'.route('reviews.like', $review).'"]')->item(0);
            $this->assertNotNull($likeForm);
            $isLiked = $role === 'reviewer' || ($role === 'owner' && $review->id === $ownReview->id);
            $likeCount = $review->id === $ownReview->id ? 2 : 1;
            $this->assertStringContainsString(($isLiked ? 'いいね済み' : 'いいね').' ('.$likeCount.')', $likeForm->textContent);
        }
        $favorite = $xpath->query('//form[@action="'.route('favorites.toggle', $book).'"]//button')->item(0);
        $this->assertSame($role === 'owner' ? 'お気に入りから削除' : 'お気に入りに追加', $favorite->getAttribute('title'));
        $this->assertSame(1, $xpath->query('//form[@action="'.route('reviews.store', $book).'"]')->length);
        $this->assertSame('選択してください', $xpath->query('//select[@name="rating"]/option[@value=""]')->item(0)->textContent);
        $this->assertSame(0, $xpath->query('//select[@name="rating"]/option[@selected]')->length);
        $this->assertSame('', $xpath->query('//textarea[@name="comment"]')->item(0)->textContent);
    }

    /** T-4-11：人工的なセッション値でフラッシュ・旧入力・項目別表示箇所を確認する。 */
    public function test_t_4_11_existing_blade_displays_session_feedback_and_return_link(): void
    {
        $book = Book::factory()->create();
        $user = User::factory()->create();
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['rating' => '評価の表示確認用エラー', 'comment' => 'コメントの表示確認用エラー']));
        $this->actingAs($user)->withSession([
            'success' => '詳細フラッシュの表示確認', '_old_input' => ['rating' => '2', 'comment' => '再表示するコメント'], 'errors' => $errors,
        ]);
        // JSONセッションを一度保存し、実際の次リクエストと同じ形式でエラーバッグを復元する。
        session()->save();
        $response = $this->get(route('books.show', $book))->assertOk()->assertSee('詳細フラッシュの表示確認');
        $xpath = $this->xpath($response);
        $this->assertSame('2', $xpath->query('//select[@name="rating"]/option[@selected]')->item(0)->getAttribute('value'));
        $this->assertSame('再表示するコメント', $xpath->query('//textarea[@name="comment"]')->item(0)->textContent);
        foreach (['rating' => '評価の表示確認用エラー', 'comment' => 'コメントの表示確認用エラー'] as $field => $message) {
            $this->assertSame($message, $xpath->query('//*[@name="'.$field.'"]/following-sibling::p')->item(0)->textContent);
        }
        $this->assertSame('← 一覧に戻る', trim($xpath->query('//a[@href="'.route('books.index').'"][contains(.,"一覧に戻る")]')->item(0)->textContent));
        $this->withSession(['success' => '一覧フラッシュの表示確認'])->get('/')->assertOk()->assertSee('一覧フラッシュの表示確認');
    }

    /** T-4-1：不存在・物理削除済みはゲストと会員とも404となり、再作成しない。 */
    public function test_t_4_1_missing_and_deleted_books_return_404_for_guests_and_members(): void
    {
        $book = Book::factory()->create();
        $id = $book->id;
        $book->delete();
        $user = User::factory()->create();
        foreach ([null, $user] as $viewer) {
            if ($viewer) {
                $this->actingAs($viewer);
            }
            $this->get('/books/'.$id)->assertNotFound();
            $this->get('/books/999999999')->assertNotFound();
            $this->assertDatabaseMissing('books', ['id' => $id]);
        }
        $this->get('/books/create')->assertOk()->assertViewIs('books.create');
        $this->get('/books')->assertStatus(405);
    }

    /** T-4-1：不正形式を実在IDへ暗黙変換せず、書籍を表示・変更しない。 */
    public function test_t_4_1_malformed_book_identifiers_do_not_resolve_existing_books(): void
    {
        config(['app.debug' => false]);
        $book = Book::factory()->create();
        $before = $book->fresh()->getAttributes();
        foreach ([$book->id.'abc', $book->id.'.0', '+'.$book->id, '-'.$book->id, $book->id.'e0'] as $identifier) {
            $this->get('/books/'.$identifier)->assertStatus(405)->assertDontSee($book->title);
            $this->assertSame($before, $book->fresh()->getAttributes());
        }
        $this->get(route('books.show', $book))->assertOk()->assertSee($book->title);
    }

    /** 描画されたHTMLをDOMとして解析し、フォーム・カード単位で比較する。 */
    private function xpath(TestResponse $response): DOMXPath
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

    /** 詳細リンクで対象カードを特定する。 */
    private function card(DOMXPath $xpath, Book $book): DOMElement
    {
        $card = $xpath->query('//a[contains(@class,"cursor-pointer")][@href="'.route('books.show', $book).'"]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $card);

        return $card;
    }
}

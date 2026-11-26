<?php

/** 公開書籍APIの検索・型・保存・認可移動・削除と障害契約を検証する。 */

namespace Tests\Feature;

use App\Http\Requests\Api\BookSaveRequest;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicBooksApiTest extends TestCase
{
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
        Book::flushEventListeners();
        DB::rollBack();
        parent::tearDown();
    }

    /** 応答と独立した入力と全保存値を用意する。 */
    private function input(array $changes = []): array
    {
        return array_replace(['user_id' => User::factory()->create()->id, 'title' => 'APIタイトル', 'author' => 'API著者', 'isbn' => '0123456789012', 'published_date' => '2099-01-02', 'genres' => [Genre::factory()->create()->id]], $changes);
    }

    private function snapshot(): array
    {
        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    /** 成功時に変更できる書籍と紐付け以外の保存値を比較する。 */
    private function withoutBook(array $snapshot, int $id): array
    {
        foreach (['books', 'book_genre'] as $table) {
            $snapshot[$table] = json_encode(array_values(array_filter(json_decode($snapshot[$table], true), fn ($row) => $row[$table === 'books' ? 'id' : 'book_id'] !== $id)));
        }

        return $snapshot;
    }

    /** 共通公開値をDB保存前の元入力から比較する。 */
    private function assertBook(array $data, array $input, int $id, array $reviews = [], bool $detail = true): void
    {
        $genres = Genre::whereKey($input['genres'])->get()->map(fn ($genre) => ['id' => $genre->id, 'name' => $genre->name])->all();
        $expected = ['id' => $id, 'title' => $input['title'], 'author' => $input['author'], 'isbn' => $input['isbn'], 'published_date' => $input['published_date'], 'description' => $input['description'] ?? null, 'image_url' => $input['image_url'] ?? null, 'genres' => $genres, 'average_rating' => count($reviews) ? round(array_sum(array_column($reviews, 'rating')) / count($reviews), 1) : null, 'review_count' => count($reviews)];
        if ($detail) {
            $expected['reviews'] = $reviews;
        }
        $this->assertSame($expected, $data);
        $this->assertIsInt($data['id']);
        $this->assertIsInt($data['review_count']);
        $this->assertTrue($data['average_rating'] === null || is_int($data['average_rating']) || is_float($data['average_rating']));
    }

    /** 応答モデルとは独立に全入力項目とジャンル集合をDBから再取得する。 */
    private function assertSavedInput(int $id, array $input): void
    {
        $book = Book::findOrFail($id)->fresh();
        $expected = [];
        foreach (['user_id', 'title', 'author', 'isbn', 'published_date', 'description', 'image_url'] as $field) {
            $value = $input[$field] ?? null;
            $expected[$field] = $value === '' ? null : $value;
        }
        $this->assertSame($expected, $book->only(array_keys($expected)));
        $genres = $input['genres'];
        sort($genres);
        $this->assertSame($genres, $book->genres()->orderBy('genres.id')->pluck('genres.id')->all());
    }

    /** T-11-1〜4・8・12・17〜20 / AC-11-01・03：公開CRUDと権限移動、全レビューを元データで照合する。 */
    public function test_public_crud_common_resource_and_web_owner_transfer(): void
    {
        $input = $this->input(['description' => '説明', 'image_url' => 'https://example.com/image.jpg']);
        $secondGenre = Genre::factory()->create();
        $input['genres'][] = $secondGenre->id;
        $beforeCreate = $this->snapshot();
        $created = $this->postJson('/api/v1/books', $input)->assertCreated()->assertJsonStructure(['data'])->json('data');
        $id = $created['id'];
        $this->assertSame($this->withoutBook($beforeCreate, $id), $this->withoutBook($this->snapshot(), $id));
        $this->assertBook($created, $input, $id);
        $this->assertBook($this->getJson('/api/v1/books/'.$id)->assertOk()->json('data'), $input, $id);
        $this->assertSavedInput($id, $input);
        $saved = Book::findOrFail($id);
        foreach (array_diff_key($input, ['genres' => true]) as $key => $value) {
            $this->assertSame($value, $saved->$key);
        }
        $author = User::factory()->create(['name' => '公開投稿者', 'email' => 'secret@example.com']);
        $expectedReviews = [];
        foreach (array_merge([1], array_fill(0, 12, 5)) as $index => $rating) {
            $review = Review::factory()->create(['book_id' => $id, 'user_id' => $author->id, 'rating' => $rating, 'comment' => '評価'.$rating, 'created_at' => $index < 4 ? '2026-01-02 03:04:05' : '2026-01-03 03:04:05']);
            array_unshift($expectedReviews, ['id' => $review->id, 'user_name' => '公開投稿者', 'rating' => $rating, 'comment' => '評価'.$rating, 'created_at' => $index < 4 ? '2026-01-02T03:04:05Z' : '2026-01-03T03:04:05Z']);
        }
        // 他書籍のレビューを混在させ、対象全件だけを返す。
        Review::factory()->create();
        $detail = $this->getJson('/api/v1/books/'.$id)->assertOk()->assertDontSee('secret@example.com')->json('data');
        $this->assertBook($detail, $input, $id, $expectedReviews);
        $this->assertSame(4.7, $detail['average_rating']);
        $list = $this->getJson('/api/v1/books')->assertOk()->json();
        $row = collect($list['data'])->firstWhere('id', $id);
        $this->assertArrayNotHasKey('reviews', $row);
        $this->assertBook($row, $input, $id, $expectedReviews, false);
        $newOwner = User::factory()->create();
        $oldOwner = User::find($input['user_id']);
        $this->actingAs($oldOwner);
        $input = array_replace($input, ['user_id' => $newOwner->id, 'title' => '登録者変更', 'author' => '更新著者', 'isbn' => '0000000000099', 'published_date' => '2088-12-31', 'description' => '更新後の説明', 'image_url' => 'http://example.com/updated.png', 'genres' => [$secondGenre->id]]);
        $beforeUpdate = $this->snapshot();
        $updated = $this->putJson('/api/v1/books/'.$id, $input)->assertOk()->json('data');
        $this->assertSame($this->withoutBook($beforeUpdate, $id), $this->withoutBook($this->snapshot(), $id));
        $this->assertBook($updated, $input, $id, $expectedReviews);
        $this->assertSavedInput($id, $input);
        $before = $this->snapshot();
        $this->get('/books/'.$id.'/edit')->assertForbidden();
        $this->put('/books/'.$id, $input)->assertForbidden();
        $this->delete('/books/'.$id)->assertForbidden();
        $this->assertSame($before, $this->snapshot());
        $this->actingAs($newOwner)->get('/books/'.$id.'/edit')->assertOk();
        $this->put('/books/'.$id, $input)->assertRedirect('/books/'.$id);
        $webDelete = Book::factory()->for($newOwner)->create();
        $this->delete('/books/'.$webDelete->id)->assertRedirect('/');
        $this->assertNull(Book::find($webDelete->id));
        $this->actingAs($oldOwner)->deleteJson('/api/v1/books/'.$id)->assertNoContent()->assertContent('');
        $this->deleteJson('/api/v1/books/'.$id)->assertNotFound()->assertExactJson(['error' => '書籍が見つかりませんでした。']);
    }

    /** T-11-5・21・22 / AC-11-02：OR検索の括り、通常文字、大小・全半角とページ分割前の除外を確認する。 */
    public function test_search_pagination_literal_matching_and_links(): void
    {
        $genre = Genre::factory()->create();
        $other = Genre::factory()->create();
        $expected = [];
        foreach (range(1, 27) as $i) {
            $match = $i % 3 !== 0;
            $book = Book::factory()->create(['title' => $i % 2 ? 'AbC!%_ '.$i : '除外タイトル', 'author' => $i % 2 ? '著者' : 'aBc!%_ '.$i, 'created_at' => '2026-02-01 00:00:00']);
            $book->genres()->attach($match ? $genre : $other);
            if ($match) {
                array_unshift($expected, $book->id);
            }
        }
        $wide = Book::factory()->create(['title' => 'ＡＢＣ!%_', 'author' => '全角']);
        $wide->genres()->attach($genre);
        $url = '/api/v1/books?'.http_build_query(['keyword' => '  abc!%_  ', 'genre_id' => $genre->id, 'per_page' => 7, 'ignored' => 'secret']);
        foreach ([1, 2, 3, 9, PHP_INT_MAX] as $page) {
            $result = $this->getJson($url.'&page='.$page)->assertOk()->json();
            $this->assertSame($page > 3 ? [] : array_slice($expected, ($page - 1) * 7, 7), array_column($result['data'], 'id'));
            $this->assertSame(18, $result['meta']['total']);
            $this->assertSame(['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total'], array_keys($result['meta']));
            $this->assertSame($page, $result['meta']['current_page']);
            $this->assertSame(3, $result['meta']['last_page']);
            if ($page > 3) {
                $this->assertNull($result['meta']['from']);
                $this->assertNull($result['meta']['to']);
                $this->assertNull($result['links']['next']);
            }
            parse_str(parse_url($result['links']['first'], PHP_URL_QUERY), $query);
            $this->assertSame(['keyword' => 'abc!%_', 'genre_id' => (string) $genre->id, 'per_page' => '7', 'page' => '1'], $query);
        }
        $huge = $this->getJson('/api/v1/books?page='.PHP_INT_MAX.'&per_page=100')->assertOk();
        $huge->assertJsonPath('data', [])->assertJsonPath('meta.current_page', PHP_INT_MAX)->assertJsonPath('meta.last_page', 1)->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null);
        $this->assertSame(28, $this->getJson('/api/v1/books?keyword=%20%20')->json('meta.total'));
        $this->assertSame(20, $this->getJson('/api/v1/books')->json('meta.per_page'));
        $this->assertSame(19, $this->getJson('/api/v1/books?genre_id='.$genre->id)->json('meta.total'));
        $this->assertSame(27, $this->getJson('/api/v1/books?keyword=abc')->json('meta.total'));
        $empty = $this->getJson('/api/v1/books?keyword=存在なし')->assertOk()->json();
        $this->assertSame([], $empty['data']);
        $this->assertSame(1, $empty['meta']['last_page']);
        $this->assertNull($empty['links']['prev']);
        $this->assertNull($empty['links']['next']);
        // 標準毎分60回制限を適用しない。
        foreach (range(1, 65) as $i) {
            $this->getJson('/api/v1/books?keyword=存在なし')->assertOk();
        }
    }

    /** T-11-4・20・22：日時とIDの逆転・同日時を混ぜ、第1・第2の並び順を独立に照合する。 */
    public function test_book_and_review_order_prioritizes_created_at_before_id(): void
    {
        $input = $this->input();
        $books = [];
        $dates = ['2026-03-03 03:04:05', '2026-03-01 03:04:05', '2026-03-03 03:04:05', '2026-03-02 03:04:05'];
        foreach ($dates as $index => $date) {
            $attributes = array_diff_key($input, ['genres' => true]);
            $attributes['title'] = '日時優先書籍'.$index;
            $attributes['isbn'] = '00000000000'.(21 + $index);
            $attributes['created_at'] = $date;
            $book = Book::factory()->create($attributes);
            $book->genres()->attach($input['genres']);
            $books[] = $book->id;
        }
        $list = $this->getJson('/api/v1/books?genre_id='.$input['genres'][0])->assertOk()->json('data');
        $this->assertSame([$books[2], $books[0], $books[3], $books[1]], array_column($list, 'id'));
        $reviewer = User::factory()->create(['name' => '日時優先投稿者']);
        $reviews = [];
        foreach ($dates as $index => $date) {
            $rating = $index + 1;
            $comment = '日時優先レビュー'.$index;
            $review = Review::factory()->create(['book_id' => $books[0], 'user_id' => $reviewer->id, 'rating' => $rating, 'comment' => $comment, 'created_at' => $date]);
            // UTCの元文字列から期待値を作り、アプリのソートや返却値を流用しない。
            $reviews[] = ['id' => $review->id, 'user_name' => '日時優先投稿者', 'rating' => $rating, 'comment' => $comment, 'created_at' => str_replace(' ', 'T', $date).'Z'];
        }
        $expected = [$reviews[2], $reviews[0], $reviews[3], $reviews[1]];
        $detail = $this->getJson('/api/v1/books/'.$books[0])->assertOk()->json('data');
        $this->assertSame($expected, $detail['reviews']);
        $input['isbn'] = '0000000000021';
        $updated = $this->putJson('/api/v1/books/'.$books[0], $input)->assertOk()->json('data');
        $this->assertSame($expected, $updated['reviews']);
        $this->assertBook($updated, $input, $books[0], $expected);
        $this->assertSavedInput($books[0], $input);
    }

    public static function integerShapes(): array
    {
        return [
            ['user_id', 'string'], ['user_id', 'float'], ['user_id', 'boolean'],
            ['genres', 'string'], ['genres', 'float'], ['genres', 'boolean'],
        ];
    }

    /** T-11-18・23：存在条件を満たすIDの型だけを変え、確定型文言と7表不変を照合する。 */
    #[DataProvider('integerShapes')]
    public function test_json_integer_types_are_strict_for_existing_references(string $field, string $shape): void
    {
        $input = $this->input();
        if ($shape === 'boolean') {
            // trueはDBで1に束縛されるため、その参照先も実在させて型以外の失敗を防ぐ。
            $user = User::find(1) ?? User::factory()->create(['id' => 1]);
            $genre = Genre::find(1) ?? Genre::factory()->create(['id' => 1]);
            $input['user_id'] = $user->id;
            $input['genres'] = [$genre->id];
        }
        $book = Book::factory()->create();
        $id = $field === 'user_id' ? $input['user_id'] : $input['genres'][0];
        $table = $field === 'user_id' ? 'users' : 'genres';
        $this->assertTrue(DB::table($table)->where('id', $id)->exists());
        $value = match ($shape) {
            'string' => (string) $id,
            'float' => (float) $id,
            'boolean' => true,
        };
        $body = array_replace($input, [$field => $field === 'user_id' ? $value : [$value]]);
        $errorKey = $field === 'user_id' ? 'user_id' : 'genres.0';
        $message = $field === 'user_id' ? 'ユーザーIDは整数で指定してください。' : 'ジャンルIDは整数で指定してください。';
        foreach (['POST' => '/api/v1/books', 'PUT' => '/api/v1/books/'.$book->id] as $method => $url) {
            $before = $this->snapshot();
            $response = $this->json($method, $url, $body, [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable();
            $this->assertSame(['message' => '入力内容に誤りがあります。', 'errors' => [$errorKey => [$message]]], $response->json());
            $this->assertSame($before, $this->snapshot());
        }
    }

    public static function confirmedErrors(): array
    {
        return [['title_required'], ['isbn_size'], ['isbn_duplicate'], ['missing_user'], ['genres_object']];
    }

    /** T-11-14・23：Q-05・22の確定文言を項目キーと文字列配列まで厳密比較する。 */
    #[DataProvider('confirmedErrors')]
    public function test_confirmed_body_validation_messages(string $case): void
    {
        $input = $this->input();
        $book = Book::factory()->create();
        // 重複のPUT対象は、重複ISBNを持つ別書籍とは分ける。
        $target = Book::factory()->create();
        $expected = match ($case) {
            'title_required' => ['title' => ['タイトルは必須です。']],
            'isbn_size' => ['isbn' => ['ISBNは13桁で入力してください。']],
            'isbn_duplicate' => ['isbn' => ['そのISBNは既に使用されています。']],
            'missing_user' => ['user_id' => ['指定したユーザーが存在しません。']],
            'genres_object' => ['genres' => ['ジャンルは配列で指定してください。']],
        };
        match ($case) {
            'title_required' => $input['title'] = '',
            'isbn_size' => $input['isbn'] = '123456789012',
            'isbn_duplicate' => $input['isbn'] = $book->isbn,
            'missing_user' => $input['user_id'] = (int) User::max('id') + 1,
            'genres_object' => $input['genres'] = (object) ['0' => $input['genres'][0]],
        };
        foreach (['POST' => '/api/v1/books', 'PUT' => '/api/v1/books/'.$target->id] as $method => $url) {
            $before = $this->snapshot();
            $response = $this->json($method, $url, $input)->assertUnprocessable();
            $this->assertSame(['message' => '入力内容に誤りがあります。', 'errors' => $expected], $response->json());
            $this->assertSame($before, $this->snapshot());
        }
    }

    /** T-11-15：APIで削除した同一IDの各入口を、有効入力と保存値比較で確認する。 */
    public function test_actual_deleted_book_is_not_recreated_by_any_endpoint(): void
    {
        $input = $this->input();
        $id = $this->postJson('/api/v1/books', $input)->assertCreated()->json('data.id');
        $this->deleteJson('/api/v1/books/'.$id)->assertNoContent()->assertContent('');
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $before = $this->snapshot();
            $this->json($method, '/api/v1/books/'.$id, $method === 'PUT' ? $input : [])->assertNotFound()->assertExactJson(['error' => '書籍が見つかりませんでした。']);
            $this->assertSame($before, $this->snapshot());
            $this->assertNull(Book::find($id));
        }
    }

    public static function subjects(): array
    {
        return [[false], [true]];
    }

    /** T-11-1・2：ゲストと別Web会員が全5経路を利用できる。 */
    #[DataProvider('subjects')]
    public function test_each_public_route_for_guest_and_web_member(bool $member): void
    {
        $input = $this->input();
        if ($member) {
            $this->actingAs(User::factory()->create());
        }
        $created = $this->postJson('/api/v1/books', $input)->assertCreated()->json('data');
        $id = $created['id'];
        $this->assertBook($created, $input, $id);
        $this->getJson('/api/v1/books')->assertOk();
        $this->assertBook($this->getJson('/api/v1/books/'.$id)->assertOk()->json('data'), $input, $id);
        $input['title'] = '主体に依存しない更新';
        $updated = $this->putJson('/api/v1/books/'.$id, $input)->assertOk()->json('data');
        $this->assertBook($updated, $input, $id);
        $this->assertSavedInput($id, $input);
        $this->deleteJson('/api/v1/books/'.$id)->assertNoContent()->assertContent('');
    }

    public static function queryCases(): array
    {
        return [['keyword[]=x', 'keyword'], ['keyword='.str_repeat('a', 256), 'keyword'], ['genre_id=999999999', 'genre_id'], ['genre_id=x', 'genre_id'], ['genre_id[]=1', 'genre_id'], ['page=0', 'page'], ['page=-1', 'page'], ['page=1.1', 'page'], ['page[]=1', 'page'], ['page=99999999999999999999999', 'page'], ['per_page=0', 'per_page'], ['per_page=101', 'per_page'], ['per_page=1.1', 'per_page']];
    }

    /** T-11-6・7・23：検索不正は保存値不変で日本語422を返す。 */
    #[DataProvider('queryCases')]
    public function test_invalid_query(string $query, string $field): void
    {
        $before = $this->snapshot();
        $response = $this->getJson('/api/v1/books?'.$query)->assertUnprocessable()->assertJsonPath('message', '入力内容に誤りがあります。')->assertJsonValidationErrors($field);
        $confirmed = match ($field) {
            'page' => 'ページ番号は1以上の整数で指定してください。',
            'per_page' => '1ページの件数は1〜100の整数で指定してください。',
            'genre_id' => $query === 'genre_id=999999999' ? '指定したジャンルが存在しません。' : 'ジャンルIDは整数で指定してください。',
            default => null,
        };
        if ($confirmed !== null) {
            $this->assertSame([$field => [$confirmed]], $response->json('errors'));
        }
        foreach ($response->json('errors') as $messages) {
            $this->assertIsArray($messages);
            $this->assertMatchesRegularExpression('/[ぁ-んァ-ヶ一-龠]/u', $messages[0]);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function invalidInputs(): array
    {
        return [
            ['user_id', null], ['user_id', 999999999],
            ['title', null], ['title', []], ['title', str_repeat('あ', 256)], ['author', str_repeat('あ', 256)], ['author', []],
            ['description', str_repeat('あ', 5001)], ['description', []], ['image_url', []], ['image_url', 'ftp://example.com/a'], ['image_url', 'https://example.com/'.str_repeat('a', 236)],
            ['isbn', '123456789012'], ['isbn', '12345678901234'], ['isbn', 1234567890123], ['isbn', '１２３４５６７８９０１２３'], ['isbn', '123456789012x'],
            ['published_date', []], ['published_date', '2026-2-01'], ['published_date', '2026-02-30'], ['published_date', 'January 1 2026'], ['published_date', '2026-01-01T00:00:00Z'],
            ['genres', []], ['genres', '1'], ['genres', 1], ['genres', (object) []], ['genres', (object) ['0' => 1]], ['genres', [999999999]], ['genres', [[]]],
        ];
    }

    /** T-11-9〜14・18・23：POSTとPUTのJSON型・境界を全保存値で確認する。 */
    #[DataProvider('invalidInputs')]
    public function test_invalid_json_inputs_for_post_and_put(string $field, mixed $value): void
    {
        $input = $this->input();
        $book = Book::factory()->create();
        foreach (['POST' => '/api/v1/books', 'PUT' => '/api/v1/books/'.$book->id] as $method => $url) {
            $body = array_replace($input, [$field => $value]);
            $before = $this->snapshot();
            $response = $this->json($method, $url, $body, [], JSON_PRESERVE_ZERO_FRACTION)->assertUnprocessable()->assertJsonPath('message', '入力内容に誤りがあります。');
            $this->assertTrue(collect(array_keys($response->json('errors')))->contains(fn ($key) => $key === $field || str_starts_with($key, $field.'.')), $response->getContent());
            if ($field === 'genres' && ($value instanceof \stdClass || is_string($value) || is_int($value))) {
                $this->assertSame(['ジャンルは配列で指定してください。'], $response->json('errors.genres'));
            }
            $this->assertSame($before, $this->snapshot());
        }
    }

    /** T-11-9〜11・19：上限値・必須省略・任意値の省略/null/空文字とquery混入を検証する。 */
    public function test_valid_boundaries_required_fields_and_optional_values(): void
    {
        $input = $this->input(['title' => str_repeat('あ', 255), 'author' => str_repeat('あ', 255), 'description' => str_repeat('あ', 5000), 'image_url' => 'https://example.com/'.str_repeat('a', 235)]);
        $id = $this->postJson('/api/v1/books', $input)->assertCreated()->json('data.id');
        $this->putJson('/api/v1/books/'.$id, $input)->assertOk();
        $this->assertSavedInput($id, $input);
        $duplicate = $input;
        $duplicate['genres'] = [$input['genres'][0], $input['genres'][0]];
        $beforeDuplicate = $this->snapshot();
        $this->putJson('/api/v1/books/'.$id, $duplicate)->assertUnprocessable()->assertJsonValidationErrors(['genres.0', 'genres.1']);
        $this->assertSame($beforeDuplicate, $this->snapshot());
        foreach (['user_id', 'title', 'author', 'isbn', 'published_date', 'genres'] as $field) {
            $body = $input;
            unset($body[$field]);
            foreach (['POST' => '/api/v1/books', 'PUT' => '/api/v1/books/'.$id] as $method => $url) {
                $before = $this->snapshot();
                $this->json($method, $url.'?'.http_build_query([$field => $input[$field]]), $body)->assertUnprocessable()->assertJsonValidationErrors($field);
                $this->assertSame($before, $this->snapshot());
            }
        }
        $omitted = $input;
        unset($omitted['description'], $omitted['image_url']);
        $this->putJson('/api/v1/books/'.$id, $omitted)->assertOk();
        $this->assertSavedInput($id, $input);
        $this->assertSame($input['description'], Book::find($id)->description);
        $this->assertSame($input['image_url'], Book::find($id)->image_url);
        foreach ([null, ''] as $value) {
            $body = array_replace($input, ['description' => $value, 'image_url' => $value]);
            $result = $this->putJson('/api/v1/books/'.$id, $body)->assertOk()->json('data');
            $this->assertNull($result['description']);
            $this->assertNull($result['image_url']);
            $this->assertNull(Book::find($id)->description);
            $this->assertNull(Book::find($id)->image_url);
            $this->assertSavedInput($id, $body);
            $body['isbn'] = $value === null ? '0000000000001' : '0000000000002';
            $this->postJson('/api/v1/books', $body)->assertCreated()->assertJsonPath('data.description', null)->assertJsonPath('data.image_url', null);
        }
        $omitted['isbn'] = '0000000000003';
        $this->postJson('/api/v1/books', $omitted)->assertCreated()->assertJsonPath('data.description', null)->assertJsonPath('data.image_url', null);
        foreach ([1, 100] as $size) {
            $this->getJson('/api/v1/books?per_page='.$size.'&keyword='.str_repeat('あ', 255))->assertOk()->assertJsonPath('meta.per_page', $size);
        }
    }

    /** T-11-15・24 / AC-11-04：構文・経路・入力・障害の優先順とヘッダーを照合する。 */
    public function test_error_contract_priority_and_safe_failure(): void
    {
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            foreach (['999999999', 'invalid', '1abc'] as $id) {
                $this->json($method, '/api/v1/books/'.$id, [])->assertNotFound()->assertExactJson(['error' => '書籍が見つかりませんでした。']);
            }
        }
        $this->call('PUT', '/api/v1/books/999999999', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{')->assertStatus(400)->assertExactJson(['message' => 'JSONの形式が正しくありません。']);
        $response = $this->patchJson('/api/v1/books/999999999', [])->assertStatus(405)->assertExactJson(['message' => 'この操作方法には対応していません。']);
        $this->assertStringContainsString('PUT', $response->headers->get('Allow'));
        $input = $this->input();
        Book::factory()->create(['isbn' => $input['isbn']]);
        $this->postJson('/api/v1/books', $input)->assertUnprocessable()->assertJsonPath('errors.isbn.0', 'そのISBNは既に使用されています。');
        $input['isbn'] = '0000000000009';
        $before = $this->snapshot();
        Book::created(fn () => throw new \RuntimeException('SQL password secret trace'));
        $this->postJson('/api/v1/books', $input)->assertStatus(500)->assertExactJson(['message' => '処理に失敗しました。時間をおいて再度お試しください。']);
        $this->assertSame($before, $this->snapshot());
    }

    /** T-11-24：中間行の変更直後に障害を起こし、本体・解除済み関連も一括で戻す。 */
    #[DataProvider('writeMethods')]
    public function test_mid_sync_failure_rolls_back_all_seven_tables(string $method): void
    {
        $input = $this->input();
        $book = Book::factory()->create();
        $book->genres()->attach(Genre::factory()->create());
        $unrelated = Book::factory()->create();
        $review = Review::factory()->for($unrelated)->create();
        User::find($input['user_id'])->favoriteBooks()->attach($unrelated);
        User::find($input['user_id'])->likedReviews()->attach($review);
        $before = $this->snapshot();
        $injected = false;
        DB::listen(function ($query) use (&$injected) {
            if (! $injected && str_starts_with($query->sql, 'insert into `book_genre`')) {
                $injected = true;
                throw new \RuntimeException('中間行保存後の模擬障害');
            }
        });
        $this->json($method, '/api/v1/books'.($method === 'PUT' ? '/'.$book->id : ''), $input)->assertStatus(500)->assertExactJson(['message' => '処理に失敗しました。時間をおいて再度お試しください。']);
        $this->assertTrue($injected);
        $this->assertSame($before, $this->snapshot());
    }

    public static function writeMethods(): array
    {
        return [['POST'], ['PUT']];
    }

    /** T-11-16：CASCADEの対象と対象外保存値を7表で比較する。 */
    public function test_delete_cascades_only_target_relations(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();
        $books = Book::factory()->count(2)->for($user)->create();
        $reviewIds = [];
        foreach ($books as $book) {
            $book->genres()->attach($genre);
            $review = Review::factory()->for($book)->for($user)->create();
            $reviewIds[] = $review->id;
            $user->favoriteBooks()->attach($book);
            $user->likedReviews()->attach($review);
        }
        $id = $books[0]->id;
        $before = $this->snapshot();
        $this->deleteJson('/api/v1/books/'.$id)->assertNoContent()->assertContent('');
        $after = $this->snapshot();
        foreach ($before as $table => $json) {
            $expected = array_values(array_filter(json_decode($json, true), fn ($row) => match ($table) {
                'books' => $row['id'] !== $id,
                'book_genre', 'reviews', 'favorites' => $row['book_id'] !== $id,
                'review_likes' => $row['review_id'] !== $reviewIds[0],
                default => true,
            }));
            $this->assertSame($expected, json_decode($after[$table], true));
        }
    }

    /** T-11-24：検証後の参照消失と既知一意制約を模擬し、全体取消と要素キーを確認する。 */
    #[DataProvider('failureCases')]
    public function test_simulated_reference_disappearance_and_database_errors(string $case): void
    {
        $input = $this->input(['isbn' => '00000000000'.match ($case) {
            'user' => '11', 'genre' => '12', 'isbn' => '13', default => '14'
        }]);
        $book = Book::factory()->create();
        $beforeRefusal = null;
        app()->afterResolving(BookSaveRequest::class, function () use ($case, $input, &$beforeRefusal) {
            if ($case === 'user') {
                User::find($input['user_id'])->delete();
            } elseif ($case === 'genre') {
                Genre::find($input['genres'][0])->delete();
            } else {
                Book::saving(function () use ($case) {
                    $exception = new QueryException('mysql', 'hidden SQL', [], new \PDOException('hidden'));
                    $exception->errorInfo = ['23000', $case === 'isbn' ? 1062 : 9999, $case === 'isbn' ? "Duplicate entry for key 'books.books_isbn_unique'" : 'hidden'];
                    throw $exception;
                });
            }
            $beforeRefusal = $this->snapshot();
        });
        $response = $this->putJson('/api/v1/books/'.$book->id, $input)->assertStatus($case === 'unexpected' ? 500 : 422);
        if ($case !== 'unexpected') {
            $response->assertJsonValidationErrors(match ($case) {
                'user' => 'user_id', 'genre' => 'genres.0', default => 'isbn'
            });
        }
        $this->assertSame($beforeRefusal, $this->snapshot());
    }

    public static function failureCases(): array
    {
        return [['user'], ['genre'], ['isbn'], ['unexpected']];
    }
}

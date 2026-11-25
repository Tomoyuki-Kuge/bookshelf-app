<?php

/** 書籍管理の認可・入力・原子保存・関連削除・競合と障害を検証する。 */

namespace Tests\Feature;

use App\Http\Requests\BookRequest;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Concerns\InteractsWithRenderedHtml;
use Tests\Concerns\ObservesConcurrentSql;
use Tests\TestCase;

class BookManagementTest extends TestCase
{
    use InteractsWithRenderedHtml;
    use ObservesConcurrentSql;

    /** testingだけで実行し、各テストの変更を取り消す。 */
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::beginTransaction();
        $this->withoutVite();
    }

    /** 模擬イベントとテストデータを後片付けする。 */
    protected function tearDown(): void
    {
        Book::flushEventListeners();
        DB::rollBack();
        parent::tearDown();
    }

    /** 有効な書籍入力をテスト自身で準備する。 */
    private function input(array $changes = []): array
    {
        return array_replace(['title' => '書籍タイトル', 'author' => '著者', 'isbn' => '0123456789012', 'published_date' => '2099-01-02', 'description' => '', 'image_url' => '', 'genres' => [Genre::factory()->create()->id]], $changes);
    }

    /** 全業務表の保存値を応答とは独立に比較する。 */
    private function snapshot(): array
    {
        return collect(['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    /** 全入力項目とジャンル集合を保存直後にDBから照合する。 */
    private function assertSavedInput(Book $book, array $input, int $ownerId): void
    {
        $book = $book->fresh();
        $expected = ['user_id' => $ownerId];
        foreach (['title', 'author', 'isbn', 'published_date', 'description', 'image_url'] as $field) {
            $expected[$field] = ($input[$field] ?? '') === '' ? null : $input[$field];
        }
        $this->assertSame($expected, $book->only(array_keys($expected)));
        $ids = $input['genres'];
        sort($ids);
        $this->assertSame($ids, $book->genres()->orderBy('genres.id')->pluck('genres.id')->all());
    }

    /** 更新許可された本体・中間行以外の全保存項目を比較できる形にする。 */
    private function unrelatedSnapshot(array $snapshot, int $bookId): array
    {
        foreach ($snapshot as $table => $json) {
            $snapshot[$table] = array_values(array_filter(json_decode($json, true), fn ($row) => match ($table) {
                'books' => $row['id'] !== $bookId,
                'book_genre' => $row['book_id'] !== $bookId,
                default => true,
            }));
        }

        return $snapshot;
    }

    /** T-6-1〜3・9・13・18・19・21 / AC-6-01・02：各操作直後の全保存値と無関係な保存値を照合する。 */
    public function test_create_and_update_preserve_owner_and_related_data(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);
        $input = $this->input(['user_id' => $other->id, 'description' => '登録時の説明', 'image_url' => 'https://example.com/original.jpg']);
        $extra = Genre::factory()->create();
        $third = Genre::factory()->create();
        $input['genres'][] = $extra->id;
        $unrelated = Book::factory()->for($other)->create();
        $unrelated->genres()->attach([$extra->id, $third->id]);
        $unrelatedReview = Review::factory()->for($unrelated)->for($owner)->create();
        $owner->favoriteBooks()->attach($unrelated);
        $other->likedReviews()->attach($unrelatedReview);
        $form = $this->createDomXPath($this->get(route('books.create'))->assertOk());
        $this->assertSame('', $form->query('//input[@name="title"]')->item(0)->getAttribute('value'));
        $this->assertSame(0, $form->query('//input[@checked]')->length);
        $beforeCreate = $this->snapshot();
        $this->post(route('books.store'), $input)->assertSessionHas('success', '書籍を登録しました。');
        $book = Book::where('isbn', $input['isbn'])->firstOrFail();
        $this->assertSavedInput($book, $input, $owner->id);
        $this->assertSame($this->unrelatedSnapshot($beforeCreate, $book->id), $this->unrelatedSnapshot($this->snapshot(), $book->id));
        $this->get(route('books.show', $book))->assertSee('書籍を登録しました。');
        $review = Review::factory()->for($book)->for($other)->create();
        $other->favoriteBooks()->attach($book);
        $owner->likedReviews()->attach($review);
        $before = $this->snapshot();
        $edit = $this->get(route('books.edit', $book))->assertOk();
        $this->assertSame('2099-01-02', $this->createDomXPath($edit)->query('//input[@name="published_date"]')->item(0)->getAttribute('value'));
        $this->assertSame(2, $this->createDomXPath($edit)->query('//input[@checked]')->length);
        $this->assertSame($before, $this->snapshot());
        foreach ([[$extra->id], [$extra->id, $third->id], [$third->id]] as $index => $ids) {
            $input = array_replace($input, ['title' => '更新済み'.$index, 'author' => '変更著者'.$index, 'isbn' => '112345678901'.$index,
                'published_date' => '2030-02-0'.($index + 1), 'description' => '更新説明'.$index, 'image_url' => 'http://example.com/updated'.$index.'.jpg', 'genres' => $ids]);
            $beforeUpdate = $this->snapshot();
            $identity = array_intersect_key($book->fresh()->getAttributes(), array_flip(['id', 'user_id', 'created_at']));
            $this->put(route('books.update', $book), $input)->assertRedirect(route('books.show', $book))->assertSessionHas('success', '書籍情報を更新しました。');
            $this->assertSavedInput($book, $input, $owner->id);
            $this->assertSame($identity, array_intersect_key($book->fresh()->getAttributes(), array_flip(['id', 'user_id', 'created_at'])));
            $this->assertSame($this->unrelatedSnapshot($beforeUpdate, $book->id), $this->unrelatedSnapshot($this->snapshot(), $book->id));
        }
        $this->get(route('books.show', $book))->assertSee('更新済み2')->assertSee('書籍情報を更新しました。');
        $this->put(route('books.update', $book), array_replace($input, ['published_date' => '2026-1-2']))->assertRedirect(route('books.show', $book));
        $this->assertSame('2026-01-02', $book->fresh()->published_date);
    }

    /** T-6-4・5：認証、対象解決、本人認可を入力検証より先に行う。 */
    public function test_authentication_target_and_owner_checks_precede_validation(): void
    {
        $owner = User::factory()->create();
        $book = Book::factory()->for($owner)->create();
        $input = $this->input();
        $before = $this->snapshot();
        foreach ([['GET', '/books/create'], ['POST', '/books'], ['GET', '/books/'.$book->id.'/edit'], ['PUT', '/books/'.$book->id], ['DELETE', '/books/'.$book->id], ['PUT', '/books/999999999']] as [$method, $url]) {
            $this->call($method, $url, [])->assertRedirect(route('login'));
        }
        $this->actingAs(User::factory()->create());
        $before = $this->snapshot();
        foreach ([[], $input] as $data) {
            foreach ([['GET', '/edit'], ['PUT', ''], ['DELETE', '']] as [$method, $suffix]) {
                $this->call($method, '/books/'.$book->id.$suffix, $data)->assertForbidden();
                $this->call($method, '/books/999999999'.$suffix, $data)->assertNotFound();
            }
        }
        foreach ([$book->id.'abc', $book->id.'.0', '+'.$book->id, '-'.$book->id, $book->id.'e0'] as $id) {
            foreach ([['GET', '/edit'], ['PUT', ''], ['DELETE', '']] as [$method, $suffix]) {
                $this->call($method, '/books/'.$id.$suffix, $input)->assertNotFound();
                $this->app['auth']->forgetGuards();
                $this->call($method, '/books/'.$id.$suffix, $input)->assertRedirect(route('login'));
                $this->actingAs($owner);
            }
        }
        $this->assertSame($before, $this->snapshot());
        $this->actingAs(User::factory()->create());
        $this->get(route('books.show', $book))->assertDontSee(route('books.edit', $book), false);
        $this->actingAs($owner)->get(route('books.show', $book))->assertSee(route('books.edit', $book), false);
    }

    /** T-6-6〜12・14・17：設計書に基づく固定の期待キー・文言を返す。 */
    public static function invalidInputs(): array
    {
        $cases = [];
        foreach (['title' => 'タイトル', 'author' => '著者', 'isbn' => 'ISBN', 'published_date' => '出版日'] as $field => $label) {
            foreach ([null, []] as $value) {
                $cases[] = [$field, $value, [$field => [$label.'は必須です。']]];
            }
            foreach ([['不正'], 123] as $value) {
                $cases[] = [$field, $value, [$field => [$label.'は文字列で入力してください。']]];
            }
        }
        foreach (['title' => 'タイトル', 'author' => '著者', 'description' => '説明', 'image_url' => '画像URL'] as $field => $label) {
            $limit = $field === 'description' ? 5000 : 255;
            $value = $field === 'image_url' ? 'https://example.com/'.str_repeat('a', 236) : str_repeat('あ', $limit + 1);
            $cases[] = [$field, $value, [$field => [$label.'は'.$limit.'文字以内で入力してください。']]];
            if (in_array($field, ['description', 'image_url'], true)) {
                $cases[] = [$field, ['不正'], [$field => [$label.'は文字列で入力してください。']]];
            }
        }
        foreach (['123456789012', '12345678901234'] as $value) {
            $cases[] = ['isbn', $value, ['isbn' => ['ISBNは13桁で入力してください。']]];
        }
        foreach (['１２３４５６７８９０１２３', '123456789012a', '123-567890123'] as $value) {
            $cases[] = ['isbn', $value, ['isbn' => ['ISBNは半角数字で入力してください。']]];
        }
        $cases[] = ['isbn', '@duplicate', ['isbn' => ['そのISBNは既に使用されています。']]];
        foreach (['2026-02-30', 'not-a-date'] as $value) {
            $cases[] = ['published_date', $value, ['published_date' => ['出版日は有効な日付で入力してください。']]];
        }
        foreach (['ftp://example.com/a', 'not-url'] as $value) {
            $cases[] = ['image_url', $value, ['image_url' => ['画像URLはHTTPまたはHTTPSのURLで入力してください。']]];
        }
        foreach ([null, [], '@omitted'] as $value) {
            $cases[] = ['genres', $value, ['genres' => ['ジャンルを1つ以上選択してください。']]];
        }

        return array_merge($cases, [
            ['genres', '1', ['genres' => ['ジャンルは配列で指定してください。']]],
            ['genres', ['@valid', 999999999], ['genres.1' => ['選択したジャンルが存在しません。']]],
            ['genres', ['@valid', 'bad'], ['genres.1' => ['ジャンルIDは整数で指定してください。']]],
            ['genres', ['@valid', [1]], ['genres.1' => ['ジャンルIDは整数で指定してください。']]],
            ['genres', ['@valid', '@valid'], ['genres.0' => ['同じジャンルを重複して選択できません。'], 'genres.1' => ['同じジャンルを重複して選択できません。']]],
        ]);
    }

    /** T-6-6〜17 / AC-6-03：POST/PUTの正確な欄下エラー・全旧入力・選択と保存値不変を照合する。 */
    #[DataProvider('invalidInputs')]
    public function test_invalid_inputs_return_to_internal_form_and_preserve_database(string $field, mixed $value, array $errors): void
    {
        $this->actingAs($user = User::factory()->create());
        $book = Book::factory()->for($user)->create();
        $duplicate = Book::factory()->create();
        $input = $this->input(['description' => '保持される説明', 'image_url' => 'https://example.com/retain.jpg']);
        $second = Genre::factory()->create();
        $input['genres'][] = $second->id;
        $book->genres()->attach($input['genres']);
        if ($value === '@duplicate') {
            $value = $duplicate->isbn;
        } elseif (is_array($value)) {
            $value = array_map(fn ($id) => $id === '@valid' ? $input['genres'][0] : $id, $value);
        }
        foreach (['POST', 'PUT'] as $method) {
            $data = array_replace($input, [$field => $value]);
            if ($value === '@omitted') {
                unset($data[$field]);
            }
            $expectedOld = [];
            foreach (['title', 'author', 'isbn', 'published_date', 'description', 'image_url'] as $name) {
                $expectedOld[$name] = is_string($data[$name]) ? $data[$name] : '';
            }
            $selected = $data['genres'] ?? [];
            $expectedOld['genres'] = is_array($selected) ? array_values(array_filter($selected, fn ($id) => is_int($id) || is_string($id))) : [];
            $before = $this->snapshot();
            $url = $method === 'POST' ? route('books.store') : route('books.update', $book);
            $form = $method === 'POST' ? route('books.create') : route('books.edit', $book);
            $this->from('https://outside.example/')->call($method, $url, $data)->assertRedirect($form);
            // Storeを直接読むことで、JSONセッションを再開始せず旧入力を検査する。
            $this->assertSame($expectedOld, $this->app['session.store']->getOldInput());
            $response = $this->get($form)->assertOk();
            $actualErrors = $this->app['view']->shared('errors')->getBag('default')->toArray();
            $this->assertSame($errors, $actualErrors);
            $dom = $this->createDomXPath($response);
            foreach (['title', 'author', 'isbn', 'published_date', 'description', 'image_url'] as $name) {
                $node = $dom->query('//*[@name="'.$name.'"]')->item(0);
                $this->assertSame($expectedOld[$name], $name === 'description' ? $node->textContent : $node->getAttribute('value'), $name.'の旧入力');
                $messages = $dom->query('./p[contains(@class,"text-red-600")]', $node->parentNode);
                $actual = array_map(fn ($node) => trim($node->textContent), iterator_to_array($messages));
                $this->assertSame($errors[$name] ?? [], $actual, $name.'の欄下文言');
            }
            $messages = $dom->query('//input[@name="genres[]"]/ancestor::div[contains(@class,"bg-gray-50")]/parent::div/p[contains(@class,"text-red-600")]');
            $actual = array_map(fn ($node) => trim($node->textContent), iterator_to_array($messages));
            $genreErrors = array_filter($errors, fn ($key) => str_starts_with($key, 'genres'), ARRAY_FILTER_USE_KEY);
            $this->assertSame(array_values(array_unique(array_merge(...array_values($genreErrors)))), $actual, 'ジャンルの欄下文言');
            $actualChecked = array_map(fn ($node) => $node->getAttribute('value'), iterator_to_array($dom->query('//input[@name="genres[]"][@checked]')));
            $displayIds = $response->viewData('genres')->pluck('id')->map(fn ($id) => (string) $id)->all();
            $expectedChecked = array_values(array_filter($displayIds, fn ($id) => in_array($id, array_map('strval', $expectedOld['genres']), true)));
            $this->assertSame($expectedChecked, $actualChecked, '有効なジャンル選択');
            $this->assertSame($before, $this->snapshot());
        }
    }

    /** T-6-7・11・12：上限ちょうど・未来日・HTTPSを登録と更新で許可する。 */
    public function test_maximum_lengths_and_valid_dates_urls_are_accepted(): void
    {
        $this->actingAs($user = User::factory()->create());
        $input = $this->input(['title' => str_repeat('あ', 255), 'author' => str_repeat('い', 255), 'description' => str_repeat('う', 5000), 'image_url' => 'https://example.com/'.str_repeat('a', 235)]);
        $this->post(route('books.store'), $input)->assertSessionHasNoErrors();
        $book = Book::where('isbn', $input['isbn'])->firstOrFail();
        $this->assertSavedInput($book, $input, $user->id);
        $this->assertSame(255, strlen($book->image_url));
        $this->put(route('books.update', $book), $input)->assertRedirect(route('books.show', $book))->assertSessionHasNoErrors();
        $this->assertSavedInput($book, $input, $user->id);
    }

    /** T-6-10・14・16・17：重複と全解除を欄下に表示し、旧入力から既存選択を復活させない。 */
    public function test_duplicate_isbn_genres_and_clear_selection_are_preserved(): void
    {
        $this->actingAs($user = User::factory()->create());
        $book = Book::factory()->for($user)->create();
        $other = Book::factory()->create();
        $input = $this->input();
        $book->genres()->attach($input['genres']);
        foreach (['POST', 'PUT'] as $method) {
            $url = $method === 'POST' ? route('books.store') : route('books.update', $book);
            $form = $method === 'POST' ? route('books.create') : route('books.edit', $book);
            $this->call($method, $url, array_replace($input, ['isbn' => $other->isbn]))->assertSessionHasErrors(['isbn' => 'そのISBNは既に使用されています。']);
            $this->call($method, $url, array_replace($input, ['genres' => [$input['genres'][0], $input['genres'][0]]]))->assertSessionHasErrors('genres.0');
            unset($input['genres']);
            $this->call($method, $url, array_replace($input, ['title' => '']))->assertRedirect($form);
            $dom = $this->createDomXPath($this->get($form)->assertOk());
            $this->assertSame('', $dom->query('//input[@name="title"]')->item(0)->getAttribute('value'));
            $this->assertSame(0, $dom->query('//input[@checked]')->length);
            $this->assertStringContainsString('タイトルは必須です。', $dom->document->textContent);
            $input['genres'] = $book->genres->modelKeys();
        }
    }

    /** T-6-15：ジャンル0件時もフォームの既存案内を維持する。 */
    public function test_no_genres_form_displays_registration_guidance(): void
    {
        $this->actingAs(User::factory()->create());
        DB::table('book_genre')->delete();
        DB::table('genres')->delete();
        $this->get(route('books.create'))->assertOk()->assertSee('ジャンルが登録されていません。先にジャンルを登録してください。');
    }

    /** T-6-18・20 / AC-6-04：全関連を物理削除し、無関係な行・ジャンル本体を保持する。 */
    public function test_delete_cascades_only_target_book_relations_and_returns_404_afterward(): void
    {
        $this->actingAs($owner = User::factory()->create());
        $other = User::factory()->create();
        $genre = Genre::factory()->create();
        $books = Book::factory()->for($owner)->count(2)->create();
        foreach ($books as $book) {
            $book->genres()->attach($genre);
            $review = Review::factory()->for($book)->for($other)->create();
            $other->favoriteBooks()->attach($book);
            $owner->likedReviews()->attach($review);
        }
        $preserved = $books[1]->fresh();
        $before = $this->snapshot();
        $this->delete(route('books.destroy', $books[0]))->assertRedirect(route('books.index'))->assertSessionHas('success', '書籍を削除しました。');
        $this->get(route('books.index'))->assertSee('書籍を削除しました。');
        $this->assertDatabaseMissing('books', ['id' => $books[0]->id]);
        foreach (['book_genre', 'reviews', 'favorites'] as $table) {
            $this->assertDatabaseMissing($table, ['book_id' => $books[0]->id]);
            $this->assertDatabaseHas($table, ['book_id' => $preserved->id]);
        }
        $targetReviewIds = collect(json_decode($before['reviews'], true))->where('book_id', $books[0]->id)->pluck('id')->all();
        foreach (['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'] as $table) {
            $expected = collect(json_decode($before[$table], true))->reject(fn ($row) => match ($table) {
                'books' => $row['id'] === $books[0]->id,
                'book_genre', 'reviews', 'favorites' => $row['book_id'] === $books[0]->id,
                'review_likes' => in_array($row['review_id'], $targetReviewIds, true),
                default => false,
            })->values()->all();
            $this->assertSame($expected, json_decode($this->snapshot()[$table], true), $table.'の対象外全保存値を保持する');
        }
        $this->assertSame($before['genres'], $this->snapshot()['genres']);
        $this->assertSame($preserved->getAttributes(), $preserved->fresh()->getAttributes());
        $input = $this->input();
        foreach ([['GET', ''], ['GET', '/edit'], ['PUT', ''], ['DELETE', '']] as [$method, $suffix]) {
            $this->call($method, '/books/'.$books[0]->id.$suffix, $input)->assertNotFound();
        }
    }

    /** T-6-22：検証後の対象消失・所有者変更も保存前に再確認する。 */
    public function test_target_deleted_or_owner_changed_after_validation_is_not_saved(): void
    {
        $this->actingAs($owner = User::factory()->create());
        $book = Book::factory()->for($owner)->create();
        $input = $this->input();
        $other = User::factory()->create();
        $book->genres()->attach($input['genres']);
        $review = Review::factory()->for($book)->for($other)->create();
        $other->favoriteBooks()->attach($book);
        $owner->likedReviews()->attach($review);
        $unrelated = Book::factory()->for($other)->create();
        $unrelated->genres()->attach($input['genres']);
        Review::factory()->for($unrelated)->for($owner)->create();
        $beforeRefusal = null;
        $this->app->afterResolving(BookRequest::class, function () use ($book, $other, &$beforeRefusal) {
            DB::table('books')->where('id', $book->id)->update(['user_id' => $other->id]);
            $beforeRefusal = $this->snapshot();
        });
        $this->put(route('books.update', $book), $input)->assertForbidden();
        $this->assertNotNull($beforeRefusal);
        $this->assertSame($beforeRefusal, $this->snapshot());
        $this->app->afterResolving(BookRequest::class, function () use ($book) {
            DB::table('books')->where('id', $book->id)->delete();
        });
        $this->actingAs($other)->put(route('books.update', $book), $input)->assertNotFound();
        $this->assertDatabaseMissing('books', ['id' => $book->id]);
    }

    /** T-6-22：保存途中のISBN競合・選択先消失・別障害を模擬し、当該変更を全取消する。 */
    public function test_save_races_and_unexpected_failures_roll_back_all_changes(): void
    {
        $this->actingAs($owner = User::factory()->create());
        $book = Book::factory()->for($owner)->create();
        $input = $this->input();
        $book->genres()->attach($input['genres']);
        foreach (['POST', 'PUT'] as $method) {
            $url = $method === 'POST' ? route('books.store') : route('books.update', $book);
            $form = $method === 'POST' ? route('books.create') : route('books.edit', $book);
            $before = $this->snapshot();
            Book::saving(function (Book $target) use ($input, $owner) {
                DB::table('books')->insert(['user_id' => $owner->id, 'title' => '競合', 'author' => '著者', 'isbn' => $input['isbn'], 'published_date' => '2026-01-01']);
            });
            $this->call($method, $url, $input)->assertRedirect($form)->assertSessionHasErrors(['isbn' => BookRequest::duplicateMessage()]);
            $this->assertSame($before, $this->snapshot());
            Book::flushEventListeners();
            // 本体保存後・sync前の参照先消失を模擬し、実外部キー制約で取り消す。
            Book::saved(function () use ($input) {
                DB::table('book_genre')->whereIn('genre_id', $input['genres'])->delete();
                DB::table('genres')->whereIn('id', $input['genres'])->delete();
            });
            $this->call($method, $url, $input)->assertRedirect($form)->assertSessionHasErrors(['genres' => BookRequest::missingGenreMessage()]);
            $this->assertSame($before, $this->snapshot());
            Book::flushEventListeners();
            $exception = new QueryException('mysql', 'secret SQL', ['password-secret'], new \PDOException('unexpected'));
            Book::saved(function () use ($exception) {
                throw $exception;
            });
            $this->withoutExceptionHandling();
            try {
                $this->call($method, $url, $input);
                $this->fail('別DB障害を再送出する必要がある');
            } catch (QueryException $actual) {
                $this->assertSame($exception, $actual);
            } finally {
                $this->withExceptionHandling();
                Book::flushEventListeners();
            }
            $this->assertSame($before, $this->snapshot());
        }
    }

    /** T-6-22の実並行ケースを返す。 */
    public static function concurrentCases(): array
    {
        return [['update'], ['delete'], ['isbn'], ['genre']];
    }

    /** T-6-22：独立プロセスの検証後に競合を確定し、後勝ち・404・入力競合を検証する。 */
    #[DataProvider('concurrentCases')]
    public function test_independent_process_handles_concurrent_save(string $case): void
    {
        config(['database.connections.book_race' => config('database.connections.mysql')]);
        $connection = DB::connection('book_race');
        $this->assertSame('testing', $connection->getDatabaseName());
        $user = User::factory()->make();
        $user->setConnection('book_race')->save();
        $genre = new Genre(['name' => '並行'.bin2hex(random_bytes(8))]);
        $genre->setConnection('book_race')->save();
        $book = Book::factory()->make(['user_id' => $user->id]);
        $book->setConnection('book_race')->save();
        $isbn = '099'.str_pad((string) $book->id, 10, '0', STR_PAD_LEFT);
        $stream = new InputStream;
        $script = <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'testing') { exit(2); }
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
$book = App\Models\Book::findOrFail($argv[1]);
Illuminate\Support\Facades\Auth::guard()->setUser(App\Models\User::findOrFail($book->user_id));
$app->afterResolving(App\Http\Requests\BookRequest::class, function () {
    echo "ready\n"; fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'save') { exit(3); }
});
$request = Illuminate\Http\Request::create('/books/'.$book->id, 'PUT', [
    'title' => '後保存', 'author' => '並行著者', 'isbn' => $argv[3], 'published_date' => '2099-01-01', 'genres' => [$argv[2]],
]);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
session()->start();
echo json_encode(['status' => $response->getStatusCode(), 'errors' => session('errors')?->toArray()], JSON_UNESCAPED_UNICODE)."\n";
CHILD;
        $process = new Process([PHP_BINARY, '-r', $script, (string) $book->id, (string) $genre->id, $isbn], base_path(), ['APP_ENV' => 'testing'], $stream, 12);
        try {
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, "ready\n")), $process->getErrorOutput());
            $connection->beginTransaction();
            if ($case === 'update') {
                $connection->table('books')->where('id', $book->id)->update(['title' => '先保存']);
            } elseif ($case === 'delete') {
                $connection->table('books')->where('id', $book->id)->delete();
            } elseif ($case === 'isbn') {
                $connection->table('books')->insert(['user_id' => $user->id, 'title' => '競合ISBN', 'author' => '著者', 'isbn' => $isbn, 'published_date' => '2026-01-01']);
            } else {
                $connection->table('genres')->where('id', $genre->id)->delete();
                $connection->commit();
            }
            $stream->write("save\n");
            if ($case !== 'genre') {
                $this->waitForSqlInProcessList($connection, $process, $case === 'isbn' ? 'update `books`' : 'for update');
                $connection->commit();
            }
            $stream->close();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            $lines = explode("\n", trim($process->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($case === 'delete' ? 404 : 302, $result['status']);
            $saved = $connection->table('books')->where('id', $book->id)->first();
            if ($case === 'update') {
                $this->assertSame('後保存', $saved->title);
                $this->assertSame([$genre->id], $connection->table('book_genre')->where('book_id', $book->id)->pluck('genre_id')->all());
            } elseif ($case === 'delete') {
                $this->assertNull($saved);
            } else {
                $this->assertSame($book->title, $saved->title);
                $field = $case === 'isbn' ? 'isbn' : 'genres';
                $this->assertArrayHasKey($field, $result['errors']);
                $this->assertSame(0, $connection->table('book_genre')->where('book_id', $book->id)->count());
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
            $connection->table('genres')->where('id', $genre->id)->delete();
            DB::purge('book_race');
        }
    }

    /** デバッグ設定・応答形式・障害種別の組合せを返す。 */
    public static function unexpectedFailures(): array
    {
        $cases = [];
        foreach ([false, true] as $debug) {
            foreach ([false, true] as $json) {
                foreach (['query', 'runtime'] as $kind) {
                    $cases[] = [$debug, $json, $kind];
                }
            }
        }

        return $cases;
    }

    /** T-6-22：設定に依存せず機密非記録・既定500とJSON500の機密非露出を確認する。 */
    #[DataProvider('unexpectedFailures')]
    public function test_unexpected_failure_logs_safe_metadata_and_returns_generic_500(bool $debug, bool $json, string $kind): void
    {
        $logging = require config_path('logging.php');
        $this->assertSame(['daily'], $logging['channels']['stack']['channels']);
        $this->assertSame(14, $logging['channels']['daily']['days']);
        $path = storage_path('logs/book-failure-'.bin2hex(random_bytes(6)).'.log');
        config(['app.debug' => $debug, 'logging.default' => 'book_failure', 'logging.channels.book_failure' => ['driver' => 'single', 'path' => $path, 'level' => 'error']]);
        $this->actingAs(User::factory()->create());
        $input = $this->input(['password' => 'password-secret', 'token' => 'token-secret', 'description' => 'request-body-secret']);
        $exception = $kind === 'query'
            ? new QueryException('mysql', 'SQL-secret', ['password-secret', 'token-secret', 'request-body-secret'], new \PDOException('database-secret'))
            : new \RuntimeException('SQL-secret password-secret token-secret request-body-secret database-secret');
        Book::saving(function () use ($exception) {
            throw $exception;
        });
        try {
            $response = $this->post(route('books.store'), $input, $json ? ['Accept' => 'application/json'] : [])->assertStatus(500);
            $this->assertSame($debug, config('app.debug'));
            if ($json) {
                $response->assertExactJson(['message' => '処理に失敗しました。時間をおいて再度お試しください。']);
            } else {
                $response->assertSee('Server Error');
                $this->assertSame(view('errors::500')->render(), $response->getContent());
            }
            $log = file_get_contents($path);
            foreach (['password-secret', 'token-secret', 'request-body-secret', 'SQL-secret', 'database-secret', 'Stack trace'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
                $this->assertStringNotContainsString($secret, $log);
            }
            $this->assertStringContainsString('occurred_at', $log);
            $this->assertStringContainsString('books', $log);
            $this->assertStringContainsString($kind === 'query' ? 'QueryException' : 'RuntimeException', $log);
        } finally {
            Log::forgetChannel('book_failure');
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /** T-6-22：実保存のジャンル共有ロックが他プロセスの削除を待たせ、同期後はRESTRICTで守る。 */
    public function test_genre_shared_lock_blocks_concurrent_delete_until_sync_commits(): void
    {
        config(['database.connections.book_lock' => config('database.connections.mysql')]);
        $connection = DB::connection('book_lock');
        $this->assertSame('testing', $connection->getDatabaseName());
        $user = User::factory()->make();
        $user->setConnection('book_lock')->save();
        $genre = new Genre(['name' => '共有ロック'.bin2hex(random_bytes(8))]);
        $genre->setConnection('book_lock')->save();
        $book = Book::factory()->make(['user_id' => $user->id]);
        $book->setConnection('book_lock')->save();
        $bootstrap = <<<'CHILD'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'testing') { exit(2); }
Illuminate\Support\Facades\DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
CHILD;
        $saveScript = $bootstrap.<<<'CHILD'
$book = App\Models\Book::findOrFail($argv[1]);
Illuminate\Support\Facades\Auth::guard()->setUser(App\Models\User::findOrFail($book->user_id));
App\Models\Book::saving(function () {
    echo "locked\n"; fflush(STDOUT);
    if (trim(fgets(STDIN)) !== 'commit') { exit(3); }
});
$request = Illuminate\Http\Request::create('/books/'.$book->id, 'PUT', ['title' => '共有ロック保存', 'author' => '著者', 'isbn' => $book->isbn, 'published_date' => '2099-01-01', 'genres' => [$argv[2]]]);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo $response->getStatusCode()."\n";
CHILD;
        $deleteScript = $bootstrap.<<<'CHILD'
$genre = App\Models\Genre::findOrFail($argv[1]);
$response = (new App\Http\Controllers\GenreController)->destroy($genre);
session()->start();
echo json_encode(['error' => session('error')], JSON_UNESCAPED_UNICODE)."\n";
CHILD;
        $stream = new InputStream;
        $save = new Process([PHP_BINARY, '-r', $saveScript, (string) $book->id, (string) $genre->id], base_path(), ['APP_ENV' => 'testing'], $stream, 12);
        $delete = new Process([PHP_BINARY, '-r', $deleteScript, (string) $genre->id], base_path(), ['APP_ENV' => 'testing'], null, 12);
        try {
            $save->start();
            $this->assertTrue($save->waitUntil(fn ($type, $output) => str_contains($output, "locked\n")), $save->getErrorOutput());
            $delete->start();
            $this->waitForSqlInProcessList($connection, $delete, 'delete from `genres`');
            $stream->write("commit\n");
            $stream->close();
            $this->assertSame(0, $save->wait(), $save->getErrorOutput());
            $this->assertStringContainsString('302', $save->getOutput());
            $this->assertSame(0, $delete->wait(), $delete->getErrorOutput());
            $result = json_decode(trim($delete->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('このジャンルには書籍が紐付いているため削除できません。', $result['error']);
            $this->assertTrue($connection->table('genres')->where('id', $genre->id)->exists());
            $this->assertTrue($connection->table('book_genre')->where('book_id', $book->id)->where('genre_id', $genre->id)->exists());
        } finally {
            foreach ([$save, $delete] as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            $stream->close();
            $connection->table('users')->where('id', $user->id)->delete();
            $connection->table('genres')->where('id', $genre->id)->delete();
            DB::purge('book_lock');
        }
    }

    /** 想定内応答の回帰もデバッグ設定の両方で確認する。 */
    public static function debugSettings(): array
    {
        return [[false], [true]];
    }

    /** T-6-22：認証・認可・対象なし・検証・メソッド・CSRFの応答を500へ変えない。 */
    #[DataProvider('debugSettings')]
    public function test_expected_responses_keep_their_status_and_debug_setting(bool $debug): void
    {
        config(['app.debug' => $debug]);
        $this->get(route('books.create'))->assertRedirect(route('login'));
        $this->getJson(route('books.create'))->assertUnauthorized();
        $this->postJson('/login', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
        $owner = User::factory()->create();
        $book = Book::factory()->for($owner)->create();
        $this->actingAs(User::factory()->create());
        $this->get(route('books.edit', $book))->assertForbidden();
        $this->getJson(route('books.edit', $book))->assertForbidden();
        $this->get('/books/999999999/edit')->assertNotFound();
        $this->getJson('/books/999999999/edit')->assertNotFound();
        $this->get('/logout')->assertStatus(405);
        $this->getJson('/logout')->assertStatus(405);
        $this->post(route('books.store'), [])->assertRedirect(route('books.create'))->assertSessionHasErrors('title');
        // PHPUnitでは通常CSRF検証を省略するため、同じ例外をHTTP経路から発生させる。
        Route::get('/_test/csrf-expired', function () {
            throw new TokenMismatchException;
        });
        $this->get('/_test/csrf-expired')->assertStatus(419);
        $this->getJson('/_test/csrf-expired')->assertStatus(419);
        $this->assertSame($debug, config('app.debug'));
    }

    /** T-6-4・22：入口のPolicy通過後に作成者が変わった削除を再認可し、全保存値を維持する。 */
    public function test_delete_reauthorizes_owner_changed_after_initial_policy_without_writing(): void
    {
        $this->actingAs($owner = User::factory()->create());
        $other = User::factory()->create();
        $book = Book::factory()->for($owner)->create();
        $book->genres()->attach(Genre::factory()->create());
        $review = Review::factory()->for($book)->for($other)->create();
        $other->favoriteBooks()->attach($book);
        $owner->likedReviews()->attach($review);
        Book::factory()->for($other)->create();
        $beforeRefusal = null;
        $checks = [];
        // 最初のcan:delete判定の成功後にDBだけを変更し、Binding済みモデルは元の所有者に保つ。
        Gate::after(function ($user, $ability, $result, $arguments) use ($book, $other, &$beforeRefusal, &$checks) {
            if ($ability === 'delete' && ($arguments[0] ?? null) instanceof Book && $arguments[0]->id === $book->id) {
                $checks[] = $result;
                if ($beforeRefusal === null && $result === true) {
                    DB::table('books')->where('id', $book->id)->update(['user_id' => $other->id]);
                    $beforeRefusal = $this->snapshot();
                }
            }
        });
        $this->delete(route('books.destroy', $book))->assertForbidden();
        $this->assertSame([true, false], $checks, '入口成功後に保存直前のPolicyが拒否する');
        $this->assertNotNull($beforeRefusal);
        $this->assertSame($beforeRefusal, $this->snapshot());
    }

    /** 任意項目の個別解除と一括解除を返す。 */
    public static function optionalClears(): array
    {
        return [[['description']], [['image_url']], [['description', 'image_url']]];
    }

    /** T-6-1・16：値のある任意項目を空欄更新するとNULLとなり、画面へ旧値を復活させない。 */
    #[DataProvider('optionalClears')]
    public function test_clearing_existing_optional_values_stores_null_and_renders_empty(array $fields): void
    {
        $this->actingAs($owner = User::factory()->create());
        $input = $this->input(['description' => '解除前の説明', 'image_url' => 'https://example.com/before-clear.jpg']);
        $this->post(route('books.store'), $input)->assertSessionHasNoErrors();
        $book = Book::where('isbn', $input['isbn'])->firstOrFail();
        $this->assertSavedInput($book, $input, $owner->id);
        foreach ($fields as $field) {
            $input[$field] = '';
        }
        $this->put(route('books.update', $book), $input)->assertRedirect(route('books.show', $book));
        $this->assertSavedInput($book, $input, $owner->id);
        $form = $this->createDomXPath($this->get(route('books.edit', $book))->assertOk());
        foreach (['description', 'image_url'] as $field) {
            $node = $form->query('//*[@name="'.$field.'"]')->item(0);
            $this->assertSame($input[$field], $field === 'description' ? $node->textContent : $node->getAttribute('value'));
        }
        $detail = $this->get(route('books.show', $book))->assertOk();
        foreach ($fields as $field) {
            $this->assertNull($book->fresh()->$field);
            if ($field === 'description') {
                $detail->assertDontSee('解除前の説明')->assertDontSee('説明:');
            } else {
                $detail->assertDontSee('https://example.com/before-clear.jpg', false)->assertSee('画像なし');
            }
        }
    }

    /** 任意項目の空欄と省略を返す。 */
    public static function optionalRegistrationInputs(): array
    {
        return [[false], [true]];
    }

    /** T-6-1：任意項目を入力しない登録も許可し、直後のDB値をNULLとして確認する。 */
    #[DataProvider('optionalRegistrationInputs')]
    public function test_registering_without_optional_values_stores_null(bool $omit): void
    {
        $this->actingAs($owner = User::factory()->create());
        $input = $this->input();
        if ($omit) {
            unset($input['description'], $input['image_url']);
        }
        $this->post(route('books.store'), $input)->assertSessionHasNoErrors();
        $book = Book::where('isbn', $input['isbn'])->firstOrFail();
        $this->assertSavedInput($book, $input, $owner->id);
        $this->assertNull($book->description);
        $this->assertNull($book->image_url);
    }
}

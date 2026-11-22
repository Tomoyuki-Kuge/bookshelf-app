<?php

/** 認証と接続段階のWeb導線をtesting専用DBで検証する。 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('testing', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        DB::beginTransaction();
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    private function registration(array $overrides = []): array
    {
        return array_replace(['name' => '登録者', 'email' => 'auth-'.bin2hex(random_bytes(8)).'@example.com', 'password' => 'password', 'password_confirmation' => 'password'], $overrides);
    }

    /** 対応するT-2の認証・導線条件を検証する。 */
    public function test_t_2_1_registration_hashes_password_and_ignores_intended_and_user_id(): void
    {
        $data = $this->registration();
        $this->withSession(['url.intended' => '/books/create']);
        $before = session()->getId();
        $this->post('/register', $data + ['user_id' => 999])->assertRedirect('/');
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame($data['name'], $user->name);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertArrayNotHasKey('password_confirmation', $user->getAttributes());
        $this->assertNotSame($before, session()->getId());
    }

    /** 対応するT-2の認証・導線条件を検証する。 */
    public function test_t_2_2_7_14_15_registration_errors_and_old_input_are_safe_and_japanese(): void
    {
        $existing = User::factory()->create();
        foreach ([
            [['name' => '', 'email' => '', 'password' => '', 'password_confirmation' => ''], ['name' => 'お名前を入力してください', 'email' => 'メールアドレスを入力してください', 'password' => 'パスワードを入力してください']],
            [['email' => 'invalid'], ['email' => 'メールアドレスはメール形式で入力してください']],
            [['email' => $existing->email], ['email' => 'そのメールアドレスは既に使用されています。']],
            [['password' => '1234567', 'password_confirmation' => '1234567'], ['password' => 'パスワードは8文字以上で入力してください']],
            [['password_confirmation' => 'different'], ['password_confirmation' => 'パスワードと一致しません']],
            [['password_confirmation' => ''], ['password_confirmation' => 'パスワードと一致しません']],
            [['password_confirmation' => null], ['password_confirmation' => 'パスワードと一致しません']],
        ] as [$input, $errors]) {
            $data = $this->registration($input);
            $count = User::count();
            $this->from('/register')->post('/register', $data)->assertRedirect('/register')->assertSessionHasErrors($errors);
            $this->assertSame($count, User::count());
            $this->assertGuest();
            $this->assertSame($data['name'] ?: null, session()->getOldInput('name'));
            $this->assertSame($data['email'] ?: null, session()->getOldInput('email'));
            $this->assertNull(session()->getOldInput('password'));
            $this->assertNull(session()->getOldInput('password_confirmation'));
            $this->get('/register')->assertOk()->assertSee('novalidate', false);
        }
        $data = $this->registration();
        unset($data['password_confirmation']);
        $this->from('/register')->post('/register', $data)->assertSessionHasErrors(['password_confirmation']);
    }

    /** 認証失敗の実際のリダイレクト先でemail欄下とパスワード非保持を確認する。 */
    public function test_t_2_6_7_15_dom_login_failure_after_redirect(): void
    {
        $user = User::factory()->create(['password' => 'CorrectPassword2026']);
        $secret = 'IncorrectPassword2026';
        $response = $this->from('/login')->followingRedirects()->post('/login', [
            'email' => $user->email, 'password' => $secret,
        ]);

        $this->assertRenderedAuthForm($response, '/login',
            ['email' => 'ログイン情報が登録されていません'],
            ['password'], [$secret], ['email' => $user->email]);
    }

    /** ログイン入力不正の実際のリダイレクト先で該当欄下の日本語を確認する。 */
    public function test_t_2_4_7_14_dom_login_validation_after_redirect(): void
    {
        $secret = 'LoginValidationSecret2026';
        $response = $this->from('/login')->followingRedirects()->post('/login', [
            'email' => 'invalid-email', 'password' => $secret,
        ]);

        $this->assertRenderedAuthForm($response, '/login',
            ['email' => 'メールアドレスはメール形式で入力してください'],
            ['password'], [$secret], ['email' => 'invalid-email']);
    }

    /** 登録入力不正の実際のリダイレクト先で各入力欄下と旧入力を確認する。 */
    public function test_t_2_2_7_14_dom_registration_validation_after_redirect(): void
    {
        $count = User::count();
        $secret = 'RegistrationValidationSecret2026';
        $response = $this->from('/register')->followingRedirects()->post('/register',
            $this->registration(['name' => '', 'email' => 'invalid-email',
                'password' => $secret, 'password_confirmation' => $secret]));

        $this->assertRenderedAuthForm($response, '/register',
            ['name' => 'お名前を入力してください', 'email' => 'メールアドレスはメール形式で入力してください'],
            ['password', 'password_confirmation'], [$secret], ['name' => '', 'email' => 'invalid-email']);
        $this->assertSame($count, User::count());
    }

    /** 確認不一致は確認欄下へ表示し、異なる双方のパスワードをHTMLへ残さない。 */
    public function test_t_2_2_7_15_dom_registration_confirmation_after_redirect(): void
    {
        $count = User::count();
        $secret = 'RegistrationPassword2026';
        $confirmation = 'DifferentConfirmation2026';
        $input = $this->registration(['password' => $secret, 'password_confirmation' => $confirmation]);
        $response = $this->from('/register')->followingRedirects()->post('/register', $input);

        $this->assertRenderedAuthForm($response, '/register',
            ['password_confirmation' => 'パスワードと一致しません'],
            ['password', 'password_confirmation'], [$secret, $confirmation],
            ['name' => $input['name'], 'email' => $input['email']]);
        $this->assertSame($count, User::count());
    }

    /** 実際の最終HTML内で同じフォームの欄下エラーと空のパスワード欄を検証する。 */
    private function assertRenderedAuthForm(TestResponse $response, string $path, array $errors, array $passwordNames, array $secrets, array $oldInput): void
    {
        $response->assertOk();
        $this->assertSame($path, '/'.request()->path());
        $this->assertGuest();
        foreach ($secrets as $secret) {
            $response->assertDontSee($secret, false);
        }
        $document = new DOMDocument;
        $this->assertTrue($document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING));
        $xpath = new DOMXPath($document);
        $forms = $xpath->query('//form[@method="POST" and @action="'.url($path).'"]');
        $this->assertCount(1, $forms);
        $form = $forms->item(0);

        foreach ($errors as $name => $message) {
            $inputs = $xpath->query('.//input[@name="'.$name.'"]', $form);
            $this->assertCount(1, $inputs);
            // エラーのコンテナは入力と同じ親の後続要素であり、その中の文言を比較する。
            $messages = $xpath->query('following-sibling::*//li', $inputs->item(0));
            $texts = [];
            foreach ($messages as $node) {
                $texts[] = trim($node->textContent);
            }
            $this->assertContains($message, $texts, $name.'欄下のエラー');
        }
        $passwords = $xpath->query('.//input[@type="password"]', $form);
        $actualNames = [];
        foreach ($passwords as $input) {
            $this->assertInstanceOf(DOMElement::class, $input);
            $actualNames[] = $input->getAttribute('name');
            $this->assertSame('', $input->getAttribute('value'));
        }
        $this->assertSame($passwordNames, $actualNames);
        foreach ($oldInput as $name => $value) {
            $inputs = $xpath->query('.//input[@name="'.$name.'"]', $form);
            $this->assertCount(1, $inputs);
            $this->assertSame($value, $inputs->item(0)->getAttribute('value'));
        }
    }

    /** 不正配列を旧入力へ残さず再表示できることを確認する。 */
    public function test_t_2_array_inputs_are_not_restored_into_text_fields(): void
    {
        $this->from('/register')->post('/register', $this->registration(['name' => ['bad'], 'email' => ['bad']]))->assertSessionHasErrors(['name', 'email']);
        $this->get('/register')->assertOk();
        $this->assertNull(session()->getOldInput('name'));
        $this->assertNull(session()->getOldInput('email'));
        $this->from('/login')->post('/login', ['email' => ['bad'], 'password' => ['bad']])->assertSessionHasErrors(['email', 'password']);
        $this->get('/login')->assertOk();
    }

    /** 登録のパスワード配列を500にせず拒否し、保存・秘密情報の保持を防ぐ。 */
    public function test_t_2_registration_rejects_array_passwords_and_confirmations(): void
    {
        // 同じ8要素の配列でも、文字列型の検証を省略できないことを確かめる。
        $password = array_fill(0, 8, 'test-value');
        foreach ([
            [['password' => $password, 'password_confirmation' => $password], ['password']],
            [['password' => $password, 'password_confirmation' => 'password'], ['password', 'password_confirmation']],
            [['password' => 'password', 'password_confirmation' => $password], ['password_confirmation']],
        ] as [$input, $errors]) {
            $before = $this->businessDataSnapshot();
            $this->from('/register')->post('/register', $this->registration($input))
                ->assertRedirect('/register')->assertSessionHasErrors($errors);
            $this->assertGuest();
            $this->assertSame($before, $this->businessDataSnapshot(), 'パスワード配列を拒否した後の全保存値');
            $this->assertNull(session()->getOldInput('password'));
            $this->assertNull(session()->getOldInput('password_confirmation'));
            $this->get('/register')->assertOk();
        }
    }

    /** 対応するT-2の認証・導線条件を検証する。 */
    public function test_t_2_3_registration_boundaries(): void
    {
        // ローカル部と各ドメインラベルの長さを守り、メール形式と文字数を独立させる。
        $email255 = str_repeat('a', 50).'@'.str_repeat('b', 50).'.'.str_repeat('c', 50).'.'.str_repeat('d', 50).'.'.str_repeat('e', 51);
        foreach ([['name' => str_repeat('名', 256)], ['email' => $email255.'d'], ['password' => '1234567', 'password_confirmation' => '1234567']] as $input) {
            $this->post('/register', $this->registration($input))->assertSessionHasErrors(array_key_first($input));
            $this->assertGuest();
        }
        $this->post('/register', $this->registration(['name' => str_repeat('名', 255), 'email' => $email255, 'password' => '12345678', 'password_confirmation' => '12345678']))->assertRedirect('/');
        $this->assertAuthenticated();
    }

    /** 対応するT-2の認証・導線条件を検証する。 */
    public function test_t_2_4_6_7_login_validation_and_authentication_failure(): void
    {
        foreach ([
            [['email' => '', 'password' => ''], ['email' => 'メールアドレスを入力してください', 'password' => 'パスワードを入力してください']],
            [['email' => 'invalid', 'password' => ''], ['email' => 'メールアドレスはメール形式で入力してください', 'password' => 'パスワードを入力してください']],
        ] as [$input, $errors]) {
            $this->from('/login')->post('/login', $input)->assertRedirect('/login')->assertSessionHasErrors($errors);
            $this->assertGuest();
        }
        $user = User::factory()->create(['password' => 'short']);
        $this->post('/login', ['email' => $user->email, 'password' => 'short'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        foreach (['missing@example.com', $user->email] as $email) {
            $this->from('/login')->post('/login', ['email' => $email, 'password' => 'wrong'])->assertRedirect('/login')->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
            $this->assertGuest();
            $this->assertSame($email, session()->getOldInput('email'));
            $this->assertNull(session()->getOldInput('password'));
            $this->get('/login')->assertOk();
        }
        $this->post('/login', ['email' => str_repeat('a', 50).'@'.str_repeat('b', 50).'.'.str_repeat('c', 50).'.'.str_repeat('d', 50).'.'.str_repeat('e', 52), 'password' => 'x'])->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
    }

    /** 対応するT-2の認証・導線条件を検証する。 */
    public function test_t_2_5_8_login_regenerates_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $this->get('/books/create')->assertRedirect('/login');
        $before = session()->getId();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/books/create');
        $this->assertNotSame($before, session()->getId());
        foreach (['/login', '/register'] as $path) {
            $this->get($path)->assertRedirect('/');
            $this->post($path)->assertRedirect('/');
        }
        session()->put('private', 'value');
        $id = session()->getId();
        $token = session()->token();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($token, session()->token());
        $this->assertNull(session('private'));
        $this->get('/books/create')->assertRedirect('/login');
    }

    /** 対応するT-2の認証・導線条件を検証する。 */
    public function test_t_2_login_throttle_is_japanese_expires_and_clears_after_success(): void
    {
        $user = User::factory()->create(['password' => 'password']);
        $input = ['email' => $user->email, 'password' => 'wrong'];
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', $input)->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
        }
        $this->post('/login', array_replace($input, ['password' => 'password']))->assertSessionHasErrors('email');
        $this->assertStringContainsString('ログインの試行回数が多すぎます。', session('errors')->first('email'));
        $this->assertGuest();
        $this->travel(61)->seconds();
        $this->post('/login', array_replace($input, ['password' => 'password']))->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', $input);
        }
        $this->post('/login', array_replace($input, ['password' => 'password']))->assertRedirect('/');
        $this->post('/logout');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', $input)->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
        }
    }

    /** 対応するT-2の認証・導線条件を検証する。 */
    public function test_t_2_9_13_all_protected_routes_redirect_guests_and_extra_auth_routes_are_absent(): void
    {
        $protected = [
            ['POST', '/logout', 'logout'], ['GET', '/books/create', 'books.create'], ['POST', '/books', 'books.store'],
            ['GET', '/books/999999999/edit', 'books.edit'], ['PUT', '/books/999999999', 'books.update'], ['DELETE', '/books/999999999', 'books.destroy'],
            ['GET', '/genres', 'genres.index'], ['GET', '/genres/create', 'genres.create'], ['POST', '/genres', 'genres.store'],
            ['GET', '/genres/999999999', 'genres.show'], ['GET', '/genres/999999999/edit', 'genres.edit'], ['PUT', '/genres/999999999', 'genres.update'], ['DELETE', '/genres/999999999', 'genres.destroy'],
            ['POST', '/books/999999999/reviews', 'reviews.store'], ['GET', '/reviews/999999999/edit', 'reviews.edit'], ['PUT', '/reviews/999999999', 'reviews.update'], ['DELETE', '/reviews/999999999', 'reviews.destroy'],
            ['POST', '/reviews/999999999/like', 'reviews.like'], ['POST', '/books/999999999/favorites', 'favorites.toggle'], ['GET', '/favorites', 'favorites.index'],
        ];
        foreach ($protected as [$method, $uri, $name]) {
            $route = Route::getRoutes()->match(Request::create($uri, $method));
            $this->assertSame($name, $route->getName());
            $this->assertContains('auth', $route->gatherMiddleware());
            $this->call($method, $uri)->assertRedirect('/login');
        }
        foreach ([['/', 'books.index'], ['/books/999999999', 'books.show'], ['/ranking', 'ranking.index']] as [$uri, $name]) {
            $route = Route::getRoutes()->match(Request::create($uri, 'GET'));
            $this->assertSame($name, $route->getName());
            $this->assertNotContains('auth', $route->gatherMiddleware());
        }
        foreach (['/forgot-password', '/reset-password/token', '/two-factor-challenge', '/email/verify', '/user/confirm-password', '/user/confirmed-password-status', '/profile'] as $uri) {
            $this->get($uri)->assertNotFound();
        }
        $this->get('/books')->assertStatus(405);
        $this->get('/logout')->assertStatus(405);
        $this->get('/ranking')->assertStatus(501);
    }

    /** 対応するT-2の認証・導線条件を検証する。 */
    public function test_t_2_10_11_12_navigation_auth_forms_and_create_form_render(): void
    {
        $this->get('/')->assertOk()->assertSee('ログイン')->assertSee('新規登録')->assertSee(route('books.create'), false)->assertSee(route('favorites.index'), false)->assertSee(route('genres.index'), false);
        foreach (['/login' => ['email', 'password'], '/register' => ['name', 'email', 'password', 'password_confirmation']] as $uri => $fields) {
            $response = $this->get($uri)->assertOk()->assertSee('novalidate', false)->assertDontSee('ランキング');
            foreach ($fields as $field) {
                $response->assertSee('name="'.$field.'"', false);
            }
            $response->assertDontSee('remember')->assertDontSee('forgot-password');
        }
        $user = User::factory()->create();
        $genre = Genre::factory()->create();
        $this->actingAs($user)->get('/')->assertOk()->assertSee($user->name)->assertSee($user->email)->assertSee('ログアウト')->assertDontSee('新規登録');
        $this->get('/books/create')->assertOk()->assertSee($genre->name)->assertSee(route('books.store'), false);
    }

    /** 既存件数を基準に全ページの順序・件数と表示用集計を検証する。 */
    public function test_t_2_read_only_book_list_orders_paginates_and_aggregates(): void
    {
        $this->assertBookListWithExistingData();
    }

    /** 先頭・同日時・途中・NULL日時の既存書籍が混在しても検証が成立する。 */
    public function test_t_2_book_list_preserves_existing_books_across_page_boundaries(): void
    {
        // 一覧検証の開始前データとして用意し、外側のtransactionで全件取り消す。
        Book::factory()->count(10)->create(['created_at' => '2030-01-01 00:00:00']);
        foreach (['2025-01-01 00:00:00', '2022-01-01 00:00:00', null] as $createdAt) {
            Book::factory()->create(['created_at' => $createdAt]);
        }

        $this->assertGreaterThan(1, $this->assertBookListWithExistingData());
    }

    /** DBの並び替えに依存せず期待列を作り、追加12冊と既存書籍を比較する。 */
    private function assertBookListWithExistingData(): int
    {
        $before = DB::table('books')->get()->keyBy('id')->all();
        $baselineCount = count($before);
        $older = Book::factory()->create(['created_at' => '2020-01-01 00:00:00']);
        $books = Book::factory()->count(11)->create(['created_at' => '2025-01-01 00:00:00']);
        $target = $books->last();
        $genre = Genre::factory()->create();
        $target->genres()->attach($genre);
        Review::factory()->for($target)->create(['rating' => 2]);
        Review::factory()->for($target)->create(['rating' => 5]);

        $rows = array_map(fn ($row) => ['id' => $row->id, 'created_at' => $row->created_at], array_values($before));
        foreach ($books->push($older) as $book) {
            $rows[] = ['id' => $book->id, 'created_at' => $book->getRawOriginal('created_at')];
        }
        // MySQLの日時降順はNULLを末尾とし、同日時はIDの降順にする。
        usort($rows, function ($left, $right) {
            if ($left['created_at'] === null || $right['created_at'] === null) {
                $dateOrder = ($left['created_at'] === null) <=> ($right['created_at'] === null);
            } else {
                $dateOrder = strcmp($right['created_at'], $left['created_at']);
            }

            return $dateOrder ?: $right['id'] <=> $left['id'];
        });
        $expected = array_column($rows, 'id');
        $this->assertCount($baselineCount + 12, $expected);
        $targetPage = intdiv(array_search($target->id, $expected, true), 10) + 1;

        foreach (array_chunk($expected, 10) as $index => $expectedPage) {
            $response = $this->get('/?page='.($index + 1))->assertOk();
            $page = $response['books'];
            $this->assertSame($expectedPage, $page->pluck('id')->all());
            $this->assertSame($baselineCount + 12, $page->total());
            $this->assertSame(count($expectedPage), $page->count());
            $this->assertSame(10, $page->perPage());
            $this->assertSame($index + 1, $page->currentPage());
            if ($index + 1 === $targetPage) {
                $response->assertSee($genre->name)->assertSee('(3.5)');
                $this->assertSame(3.5, (float) $page->firstWhere('id', $target->id)->reviews_avg_rating);
            }
        }
        // 一覧表示が開始前の既存書籍を更新・削除していないことも確認する。
        $this->assertEquals($before, DB::table('books')->whereIn('id', array_keys($before))->get()->keyBy('id')->all());

        return $targetPage;
    }

    /** 各入口の本人認可と、本体・関連・無関係な保存値の不変を個別に確認する。 */
    public function test_t_2_policy_checks_each_edit_update_and_delete_without_writing(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $unrelatedUser = User::factory()->create();
        $book = Book::factory()->for($owner)->create([
            'description' => '保存済みの説明', 'image_url' => 'https://example.com/original.jpg',
        ]);
        $genre = Genre::factory()->create();
        $replacementGenre = Genre::factory()->create();
        $book->genres()->attach($genre);
        $review = Review::factory()->for($other)->for($book)->create([
            'rating' => 2, 'comment' => '保存済みのレビュー',
        ]);
        $other->favoriteBooks()->attach($book);
        $owner->likedReviews()->attach($review);

        // 無関係な書籍と全関連行も用意し、誤った一括更新や削除を検出する。
        $unrelatedBook = Book::factory()->for($unrelatedUser)->create([
            'description' => '無関係な説明', 'image_url' => 'https://example.com/unrelated.jpg',
        ]);
        $unrelatedGenre = Genre::factory()->create();
        $unrelatedBook->genres()->attach($unrelatedGenre);
        $unrelatedReview = Review::factory()->for($unrelatedUser)->for($unrelatedBook)->create([
            'rating' => 3, 'comment' => '無関係なレビュー',
        ]);
        $unrelatedUser->favoriteBooks()->attach($unrelatedBook);
        $other->likedReviews()->attach($unrelatedReview);

        $bookInput = [
            'title' => '変更後タイトル', 'author' => '変更後著者', 'isbn' => $book->isbn,
            'published_date' => '2027-01-02', 'description' => '変更後の説明',
            'image_url' => 'https://example.com/updated.jpg', 'genres' => [$replacementGenre->id],
        ];
        $reviewInput = ['rating' => 5, 'comment' => '変更後のレビュー'];
        foreach (['book' => $book, 'review' => $review] as $kind => $model) {
            $path = '/'.($kind === 'book' ? 'books' : 'reviews').'/'.$model->id;
            $requests = [
                ['GET', $path.'/edit', []],
                ['PUT', $path, $kind === 'book' ? $bookInput : $reviewInput],
                ['DELETE', $path, []],
            ];
            // 書籍の他人操作と、書籍作成者による他人レビュー操作を拒否する。
            $attacker = $kind === 'book' ? $other : $owner;
            $this->actingAs($attacker);
            // 本人のuser_idを送信しても、保存済みの所有者を偽装できない。
            $forgedRequests = [
                ['PUT', $path, ($kind === 'book' ? $bookInput : $reviewInput) + ['user_id' => $attacker->id]],
                ['DELETE', $path, ['user_id' => $attacker->id]],
            ];
            foreach (array_merge($requests, $forgedRequests) as [$method, $uri, $input]) {
                $before = $this->businessDataSnapshot();
                $this->call($method, $uri, $input)->assertForbidden();
                $context = $method.' '.$uri.(array_key_exists('user_id', $input) ? '（user_id偽造）' : '');
                $this->assertSame($before, $this->businessDataSnapshot(), $context.' 拒否後の全保存値');
            }

            $this->actingAs($kind === 'book' ? $owner : $other);
            foreach ($requests as [$method, $uri, $input]) {
                $before = $this->businessDataSnapshot();
                $this->call($method, $uri, $input)->assertStatus(501);
                $this->assertSame($before, $this->businessDataSnapshot(), $method.' '.$uri.' 未実装応答後の全保存値');
            }
            $this->get(str_replace('/'.$model->id, '/999999999', $path).'/edit')->assertNotFound();
        }
    }

    /** 実際に削除した書籍への各操作は404となり、書籍や関連行を再作成しない。 */
    public function test_t_2_deleted_book_edit_update_delete_return_404_without_recreation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $genre = Genre::factory()->create();
        $book = Book::factory()->for($owner)->create();
        $book->genres()->attach($genre);
        $review = Review::factory()->for($other)->for($book)->create();
        $other->favoriteBooks()->attach($book);
        $owner->likedReviews()->attach($review);

        // 比較用の書籍と関連行は削除対象から独立させ、HTTP操作後も保持する。
        $preservedBook = Book::factory()->for($other)->create();
        $preservedBook->genres()->attach($genre);
        $preservedReview = Review::factory()->for($owner)->for($preservedBook)->create();
        $owner->favoriteBooks()->attach($preservedBook);
        $other->likedReviews()->attach($preservedReview);
        $id = $book->id;
        $input = [
            'title' => '削除済み書籍の更新入力', 'author' => '更新著者', 'isbn' => $book->isbn,
            'published_date' => '2027-01-02', 'description' => '更新説明',
            'image_url' => 'https://example.com/deleted-book.jpg', 'genres' => [$genre->id],
        ];
        // Web削除は接続段階で501のため、物理削除した状態だけをモデルで準備する。
        $this->assertTrue($book->delete());
        $this->assertDatabaseMissing('books', ['id' => $id]);
        $this->actingAs($owner);

        $this->assertDeletedTargetRequestsKeepData('books', $id, '/books/'.$id, $input);
    }

    /** レビュー単独削除後の各操作は404となり、親書籍と他レビューを変更しない。 */
    public function test_t_2_deleted_review_edit_update_delete_return_404_without_recreation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->for($other)->create();
        $genre = Genre::factory()->create();
        $book->genres()->attach($genre);
        $owner->favoriteBooks()->attach($book);
        $review = Review::factory()->for($owner)->for($book)->create();
        $other->likedReviews()->attach($review);
        $preservedReview = Review::factory()->for($other)->for($book)->create();
        $owner->likedReviews()->attach($preservedReview);
        $id = $review->id;
        $this->assertTrue($review->delete());
        $this->assertDatabaseMissing('reviews', ['id' => $id]);
        $this->assertDatabaseHas('books', ['id' => $book->id]);
        $this->actingAs($owner);

        $this->assertDeletedTargetRequestsKeepData('reviews', $id, '/reviews/'.$id,
            ['rating' => 5, 'comment' => '削除済みレビューの更新入力'], $book->id);
    }

    /** 削除準備後を基準に、各HTTP操作直後の不存在と全保存値の不変を確認する。 */
    private function assertDeletedTargetRequestsKeepData(string $table, int $id, string $path, array $input, ?int $parentBookId = null): void
    {
        foreach ([['GET', $path.'/edit', []], ['PUT', $path, $input], ['DELETE', $path, []]] as [$method, $uri, $data]) {
            $before = $this->businessDataSnapshot();
            $this->call($method, $uri, $data)->assertNotFound();
            $this->assertSame($before, $this->businessDataSnapshot(), $method.' '.$uri.' 削除済み対象への操作後の全保存値');
            $this->assertDatabaseMissing($table, ['id' => $id]);
            if ($parentBookId !== null) {
                $this->assertDatabaseHas('books', ['id' => $parentBookId]);
            }
        }
    }

    /** 全7業務表の全行・全カラムをDBから主キー順で取得し厳密比較用の配列にする。 */
    private function businessDataSnapshot(): array
    {
        $snapshot = [];
        foreach (['users', 'books', 'genres', 'book_genre', 'reviews', 'favorites', 'review_likes'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()
                ->map(fn ($row) => (array) $row)->all();
        }

        return $snapshot;
    }
}

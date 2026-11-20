<?php

/** 初期データの固定値・再実行・依存不足・途中失敗をtesting専用DBで検証する。 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\BookSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\FavoriteSeeder;
use Database\Seeders\GenreSeeder;
use Database\Seeders\ReviewLikeSeeder;
use Database\Seeders\ReviewSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class SeederTest extends TestCase
{
    private const USERS = [['山田太郎', 'yamada@example.com'], ['鈴木花子', 'suzuki@example.com'], ['田中一郎', 'tanaka@example.com'], ['佐藤美咲', 'sato@example.com'], ['高橋健太', 'takahashi@example.com']];

    private const BOOKS = [['吾輩は猫である', '夏目漱石', '9784101010014', '1905-01-01', ['小説'], '猫の視点を通して人間社会を描いた夏目漱石の小説。'], ['人を動かす', 'D・カーネギー', '9784422100524', '1936-10-01', ['ビジネス', '自己啓発'], '人との関わり方と信頼関係を築く考え方を紹介する一冊。'], ['リーダブルコード', 'Dustin Boswell', '9784873115658', '2012-06-23', ['技術書'], '読み手に意図が伝わるコードを書くための工夫を解説する技術書。'], ['7つの習慣', 'スティーブン・R・コヴィー', '9784863940246', '2013-08-30', ['ビジネス', '自己啓発'], '日々の行動と人間関係を見直すための七つの習慣を紹介する一冊。'], ['坊っちゃん', '夏目漱石', '9784101010021', '1906-04-01', ['小説'], '新任教師を主人公に、学校で起こる騒動を描いた夏目漱石の小説。'], ['サピエンス全史', 'ユヴァル・ノア・ハラリ', '9784309226712', '2016-09-08', ['歴史', '科学'], '人類の歩みを歴史と科学の観点からたどる一冊。'], ['Clean Code', 'Robert C. Martin', '9784048930598', '2017-12-18', ['技術書'], '保守しやすいコードの考え方と実践例を紹介する技術書。'], ['嫌われる勇気', '岸見一郎・古賀史健', '9784478025819', '2013-12-13', ['自己啓発'], '対話形式でアドラー心理学の考え方を紹介する一冊。'], ['火花', '又吉直樹', '9784163902302', '2015-03-11', ['小説'], 'お笑いの世界で生きる若者の交流と葛藤を描いた小説。'], ['FACTFULNESS', 'ハンス・ロスリング', '9784822289607', '2019-01-11', ['ビジネス', '科学'], 'データをもとに世界を捉えるための考え方を紹介する一冊。'], ['コンテナ物語', 'マルク・レビンソン', '9784822251468', '2007-01-18', ['ビジネス', '歴史'], 'コンテナ輸送が物流と世界経済にもたらした変化をたどる一冊。']];

    private const REVIEWS = [[1, 'A', 5, '猫の語り口が面白く、人間観察の細かさを楽しめました。'], [1, 'B', 4, '当時の暮らしが会話から伝わり、印象に残りました。'], [1, 'C', 3, '独特の表現に慣れるまで時間がかかりましたが、風刺が興味深かったです。'], [1, 'D', 4, '登場人物のやり取りを味わいながら読み進められました。'], [2, 'B', 5, '相手の立場を考える大切さを、具体例から学べました。'], [2, 'E', 4, '身近な人との接し方を見直すきっかけになりました。'], [3, 'A', 5, '名前の付け方を見直し、コードの意図を伝える参考になりました。'], [3, 'C', 4, '短い例が多く、普段の実装に取り入れやすかったです。'], [3, 'E', 3, '基本を整理するのに役立ち、チームで共有したい内容でした。'], [4, 'B', 4, '優先順位を考え直すための視点が参考になりました。'], [4, 'C', 5, '習慣を日常の行動へ落とし込む考え方が印象的でした。'], [4, 'D', 3, '一度に実践するのは難しいですが、少しずつ試したいです。'], [5, 'A', 4, '主人公の率直な語りに引き込まれ、テンポよく読めました。'], [5, 'D', 5, '学校の人間関係が生き生きと描かれ、読後も印象に残りました。'], [5, 'E', 3, '主人公の行動に驚きながら、時代の空気を楽しめました。'], [6, 'A', 5, '長い歴史を大きな流れとして捉える視点が新鮮でした。'], [6, 'B', 4, '人類の変化について、身近な常識を問い直せました。'], [6, 'C', 3, '情報量が多く、気になる章を読み返しながら理解しました。'], [7, 'B', 4, 'コードの改善を考える際の具体的な手掛かりになりました。'], [7, 'D', 5, '保守する人を意識して実装する大切さを再確認しました。'], [7, 'E', 3, '例と自分の開発環境を比較しながら読むと参考になりました。'], [8, 'A', 4, '対話を追うことで、考え方の違いを整理できました。'], [8, 'C', 5, '人間関係で自分が選べる行動について考える機会になりました。'], [8, 'D', 3, 'すぐに納得できない部分もあり、時間を置いて読み返したいです。'], [9, 'B', 4, '会話と心情の描写から、二人の距離の変化を感じました。'], [9, 'C', 3, '夢を追う生活の厳しさが伝わり、余韻が残りました。'], [9, 'E', 5, '登場人物の葛藤が丁寧に描かれ、最後まで引き込まれました。'], [10, 'A', 5, '数字を確かめてから判断する姿勢を意識するようになりました。'], [10, 'B', 4, '思い込みを点検する具体例が分かりやすかったです。'], [10, 'D', 3, '紹介されたデータの背景も調べながら読みたい内容でした。'], [11, 'A', 4, '物流の仕組みが社会を変える過程を興味深く読めました。'], [11, 'C', 5, '身近な輸送の背景にある歴史を知ることができました。']];

    private const TABLES = ['users', 'genres', 'books', 'book_genre', 'reviews', 'favorites', 'review_likes'];

    /** 既存行を消さず、空のtesting DBで各シナリオをトランザクション実行する。 */
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('testing', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
        foreach (self::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table.'に既存行があるため削除せず停止します。');
        }
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(fn () => DB::rollBack());
        $this->withoutVite();
    }

    /** 全業務列・関連・日時を取得し、失敗前後の不変を比較する。 */
    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    /** 自然キーで固定期待値の参照先を取得する。 */
    private function references(): array
    {
        $users = [];
        foreach (self::USERS as $index => [$name, $email]) {
            $users[chr(65 + $index)] = User::where('email', $email)->firstOrFail();
        }
        $books = [];
        foreach (self::BOOKS as $index => $data) {
            $books[$index + 1] = Book::where('isbn', $data[2])->firstOrFail();
        }

        return [$users, $books];
    }

    /** T-3-1〜7・11・15・17・19：単独責務と依存順、初回の固定値を確認する。 */
    public function test_initial_seeders_only_write_owned_tables_and_match_all_samples(): void
    {
        $ownership = [UserSeeder::class => ['users'], GenreSeeder::class => ['genres'], BookSeeder::class => ['books', 'book_genre'], ReviewSeeder::class => ['reviews'], FavoriteSeeder::class => ['favorites'], ReviewLikeSeeder::class => ['review_likes']];
        foreach ($ownership as $class => $owned) {
            $before = $this->snapshot();
            $this->seed($class);
            $after = $this->snapshot();
            foreach (array_diff(self::TABLES, $owned) as $table) {
                $this->assertSame($before[$table], $after[$table], $class.'が担当外の'.$table.'を変更');
            }
        }
        $this->assertSame([5, 10, 11, 16, 32, 19, 48], array_map(fn ($table) => DB::table($table)->count(), self::TABLES));
        $this->assertSame(['小説', 'ビジネス', '技術書', '自己啓発', 'エッセイ', '歴史', '科学', '芸術', '料理', '旅行'], Genre::orderBy('id')->pluck('name')->all());
        [$users, $books] = $this->references();
        foreach (self::USERS as [$name, $email]) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame($name, $user->name);
            $this->assertNotSame('password', $user->password);
            $this->assertTrue(Hash::check('password', $user->password));
        }
        foreach (self::BOOKS as $index => [$title, $author, $isbn, $date, $genres, $description]) {
            $book = $books[$index + 1];
            $this->assertSame([$title, $author, $isbn, $date, $description, $users['A']->id, 'https://placehold.co/200x300/e2e8f0/475569?text='.($index + 1)], [$book->title, $book->author, $book->isbn, $book->published_date, $book->description, $book->user_id, $book->image_url]);
            $this->assertEqualsCanonicalizing($genres, $book->genres()->pluck('name')->all());
        }
        $this->assertSame([4, 2, 3, 3, 3, 3, 3, 3, 3, 3, 2], array_values(array_map(fn ($book) => $book->reviews()->count(), $books)));
        foreach (Review::orderBy('id')->get() as $index => $review) {
            [$number, $letter, $rating, $comment] = self::REVIEWS[$index];
            $this->assertSame([$books[$number]->id, $users[$letter]->id, $rating, $comment], [$review->book_id, $review->user_id, $review->rating, $review->comment]);
            $others = array_values(array_diff(array_keys($users), [$letter]));
            $expected = array_map(fn ($key) => $users[$key]->id, array_slice($others, 0, $index % 4));
            $this->assertEqualsCanonicalizing($expected, $review->likedByUsers()->pluck('users.id')->all());
        }
        $favorites = ['A' => [1, 3, 6], 'B' => [2, 4, 8, 10], 'C' => [1, 3, 6, 9, 11], 'D' => [4, 5, 7], 'E' => [2, 7, 9, 11]];
        foreach ($favorites as $letter => $numbers) {
            $this->assertEqualsCanonicalizing(array_map(fn ($number) => $books[$number]->id, $numbers), $users[$letter]->favoriteBooks()->pluck('books.id')->all());
        }
    }

    /** T-3-1・7、AC-3-01：統括投入と一覧全ページの指定ISBNを確認する。 */
    public function test_database_seeder_supports_cli_and_the_eleven_books_paginate_ten_and_one(): void
    {
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertExitCode(0);
        $this->assertSame([5, 10, 11, 16, 32, 19, 48], array_map(fn ($table) => DB::table($table)->count(), self::TABLES));
        $shown = [];
        foreach ([1 => 10, 2 => 1] as $page => $count) {
            $response = $this->get('/?page='.$page)->assertOk();
            $paginator = $response->viewData('books');
            $this->assertSame(11, $paginator->total());
            $this->assertSame($count, $paginator->count());
            foreach ($paginator as $book) {
                $response->assertSee($book->title);
                $shown[] = $book->isbn;
            }
        }
        $this->assertEqualsCanonicalizing(array_column(self::BOOKS, 2), $shown);
        $this->assertSame(count($shown), count(array_unique($shown)));
    }

    /** AC-3-03の認証部分：5名の実パスワードでログインできる。画面完成は後続単位。 */
    public function test_all_five_seeded_users_can_log_in(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (self::USERS as [$name, $email]) {
            $this->post('/login', ['email' => $email, 'password' => 'password'])->assertRedirect('/');
            $this->assertAuthenticatedAs(User::where('email', $email)->firstOrFail());
            $this->get('/')->assertOk()->assertSee($name);
            $this->post('/logout')->assertRedirect('/login');
            $this->assertGuest();
        }
    }

    /** T-3-8・12〜14・18：自然キー再利用とUser::first()の既存DBでの扱いを確認する。 */
    public function test_existing_values_are_preserved_and_book_genres_are_resynchronized(): void
    {
        $first = User::factory()->create(['email' => 'first@example.com', 'name' => '既存利用者']);
        $yamada = User::factory()->create(['email' => 'yamada@example.com', 'name' => '変更済み山田', 'password' => 'existing-password']);
        $userBefore = $yamada->fresh()->getAttributes();
        $this->seed([UserSeeder::class, GenreSeeder::class, BookSeeder::class]);
        $this->assertSame([$first->id], Book::pluck('user_id')->unique()->values()->all());
        $book = Book::where('isbn', self::BOOKS[0][2])->firstOrFail();
        $book->update(['user_id' => $yamada->id, 'title' => '編集済み', 'author' => '既存著者', 'published_date' => '2000-02-03', 'image_url' => null, 'description' => '編集済み説明']);
        $bookBefore = $book->fresh()->getAttributes();
        $book->genres()->sync(Genre::whereIn('name', ['科学', '芸術'])->pluck('id')->all());
        $before = $this->snapshot();
        $this->seed([UserSeeder::class, GenreSeeder::class]);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($userBefore, $yamada->fresh()->getAttributes());
        $this->seed(BookSeeder::class);
        $this->assertSame($bookBefore, $book->fresh()->getAttributes());
        $this->assertSame(['小説'], $book->genres()->pluck('name')->all());
        $this->assertSame(6, User::count());
        $this->assertSame(10, Genre::count());
        $this->assertSame(11, Book::count());
    }

    /** T-3-9・16・19：再投入はレビューだけ32件増え、既存組と無関係レビューを維持する。 */
    public function test_reseeding_adds_reviews_preserves_existing_pairs_and_ignores_other_reviews(): void
    {
        $this->seed(DatabaseSeeder::class);
        [$users, $books] = $this->references();
        $other = Review::create(['book_id' => $books[1]->id, 'user_id' => $users['A']->id, 'rating' => 1, 'comment' => '通常の別レビュー']);
        $users['A']->favoriteBooks()->attach($books[11]);
        $firstReview = Review::orderBy('id')->firstOrFail();
        $firstReview->likedByUsers()->attach(array_map(fn ($user) => $user->id, $users));
        $other->likedByUsers()->attach($users['A']);
        // PAD SPACE照合で一致する末尾空白付き本文も、固定サンプルとは区別する。
        $sample = Review::orderBy('id')->skip(1)->firstOrFail();
        $similar = Review::create(['book_id' => $sample->book_id, 'user_id' => $sample->user_id, 'rating' => $sample->rating, 'comment' => $sample->comment.' ']);
        $before = $this->snapshot();
        $this->seed([FavoriteSeeder::class, ReviewLikeSeeder::class]);
        $this->assertSame($before, $this->snapshot());
        $this->seed(DatabaseSeeder::class);
        $after = $this->snapshot();
        foreach (['users', 'genres', 'books', 'book_genre', 'favorites'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        $this->assertSame(66, Review::count());
        $beforeReviewIds = array_column($before['reviews'], 'id');
        $this->assertSame($before['reviews'], array_values(array_filter($after['reviews'], fn ($row) => in_array($row['id'], $beforeReviewIds, true))));
        $this->assertSame($before['review_likes'], array_values(array_filter($after['review_likes'], fn ($row) => in_array($row['review_id'], $beforeReviewIds, true))));
        $this->assertSame(102, DB::table('review_likes')->count());
        $this->assertSame([$users['A']->id], $other->likedByUsers()->pluck('users.id')->all());
        $this->assertSame(0, $similar->likedByUsers()->count());
        // ID差分で今回の追加分を特定し、独立した固定期待値で内容といいねの全組を照合する。
        $newReviews = Review::with(['book', 'user', 'likedByUsers'])->whereNotIn('id', $beforeReviewIds)->orderBy('id')->get();
        $this->assertCount(32, $newReviews);
        foreach ($newReviews as $index => $review) {
            [$number, $letter, $rating, $comment] = self::REVIEWS[$index];
            $email = self::USERS[ord($letter) - ord('A')][1];
            $this->assertSame([self::BOOKS[$number - 1][2], $email, $rating, $comment], [$review->book->isbn, $review->user->email, $review->rating, $review->comment]);
            $others = array_values(array_diff(array_column(self::USERS, 1), [$email]));
            $expectedLikes = array_slice($others, 0, $index % 4);
            $actualLikes = $review->likedByUsers->pluck('email')->all();
            $this->assertEqualsCanonicalizing($expectedLikes, $actualLikes);
            $this->assertNotContains($email, $actualLikes);
        }
    }

    /** 不足条件と、それ以前に準備するSeederを返す。 */
    public static function missingDependencies(): array
    {
        return [
            'book-user' => [BookSeeder::class, [], '利用者', null],
            'book-last-genre' => [BookSeeder::class, [UserSeeder::class, GenreSeeder::class], '旅行', 'genre'],
            'review-users' => [ReviewSeeder::class, [], '利用者', null],
            'review-last-user' => [ReviewSeeder::class, [UserSeeder::class, GenreSeeder::class, BookSeeder::class], '利用者 takahashi@example.com', 'user'],
            'review-last-book' => [ReviewSeeder::class, [UserSeeder::class, GenreSeeder::class, BookSeeder::class], '9784822251468', 'book'],
            'favorite-users' => [FavoriteSeeder::class, [], '利用者', null],
            'favorite-last-user' => [FavoriteSeeder::class, [UserSeeder::class, GenreSeeder::class, BookSeeder::class], '利用者 takahashi@example.com', 'user'],
            'favorite-last-book' => [FavoriteSeeder::class, [UserSeeder::class, GenreSeeder::class, BookSeeder::class], '9784822251468', 'book'],
            'like-users' => [ReviewLikeSeeder::class, [], '利用者', null],
            'like-last-user' => [ReviewLikeSeeder::class, [DatabaseSeeder::class], '利用者 takahashi@example.com', 'user'],
            'like-last-book' => [ReviewLikeSeeder::class, [UserSeeder::class, GenreSeeder::class, BookSeeder::class], '9784822251468', 'book'],
            'like-last-review' => [ReviewLikeSeeder::class, [DatabaseSeeder::class], 'レビューサンプル行 32', 'review'],
        ];
    }

    /**
     * T-3-18：依存不足は最初の書込より前に拒否し、補完の自動実行をしない。
     *
     * @dataProvider missingDependencies
     */
    public function test_missing_dependencies_are_rejected_before_any_write(string $class, array $prepare, string $reason, ?string $missing): void
    {
        foreach ($prepare as $seeder) {
            $this->seed($seeder);
        }
        if ($missing === 'genre') {
            Genre::where('name', '旅行')->delete();
        } elseif ($missing === 'book') {
            Book::where('isbn', '9784822251468')->delete();
        } elseif ($missing === 'review') {
            Review::orderByDesc('id')->firstOrFail()->delete();
        } elseif ($missing === 'user') {
            User::where('email', 'takahashi@example.com')->firstOrFail()->delete();
            // E削除のCASCADE後も、先に検査されるA〜Dと全11書籍が揃っていることを確かめる。
            $this->assertEqualsCanonicalizing(array_column(array_slice(self::USERS, 0, 4), 1), User::pluck('email')->all());
            $this->assertEqualsCanonicalizing(array_column(self::BOOKS, 2), Book::pluck('isbn')->all());
            $this->assertSame(10, Genre::count());
            if ($class === ReviewLikeSeeder::class) {
                $this->assertSame(count(array_filter(self::REVIEWS, fn ($row) => $row[1] !== 'E')), Review::count());
            }
        }
        $before = $this->snapshot();
        $writes = 0;
        $this->observeQueries(function ($event) use (&$writes) {
            if (preg_match('/^(insert|update|delete) /i', $event->sql)) {
                $writes++;
            }
        }, function () use ($class, $reason, $missing) {
            try {
                $this->seed($class);
                $this->fail('依存不足なのに成功しました。');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString($class, $exception->getMessage());
                $this->assertStringContainsString('不足', $exception->getMessage());
                $this->assertStringContainsString($reason, $exception->getMessage());
                if ($missing === 'user') {
                    $this->assertSame($class.'：初期データの依存先が不足しています（利用者 takahashi@example.com）。', $exception->getMessage());
                }
            }
        });
        $this->assertSame(0, $writes);
        $this->assertSame($before, $this->snapshot());
    }

    /** クエリ監視を対象操作内に限定し、他テストへリスナーを残さない。 */
    private function observeQueries(callable $listener, callable $operation): void
    {
        $connection = DB::connection();
        $original = $connection->getEventDispatcher();
        $connection->setEventDispatcher(clone $original);
        DB::listen($listener);
        try {
            $operation();
        } finally {
            $connection->setEventDispatcher($original);
        }
    }

    /** 全体・各単独実行の途中失敗を返す。 */
    public static function failedSeeders(): array
    {
        return array_map(fn ($class) => [$class], [UserSeeder::class, GenreSeeder::class, BookSeeder::class, ReviewSeeder::class, FavoriteSeeder::class, ReviewLikeSeeder::class, DatabaseSeeder::class]);
    }

    /**
     * T-3-18：実DBで2回目の書込直後に障害を注入し、値・既存関連も復元する。
     *
     * @dataProvider failedSeeders
     */
    public function test_partial_failures_roll_back_the_whole_invocation(string $class): void
    {
        $this->seed(DatabaseSeeder::class);
        [$users, $books] = $this->references();
        $table = match ($class) {
            UserSeeder::class => 'users', GenreSeeder::class => 'genres', BookSeeder::class => 'books',
            ReviewSeeder::class => 'reviews', FavoriteSeeder::class => 'favorites', default => 'review_likes',
        };
        if ($class === UserSeeder::class || $class === DatabaseSeeder::class) {
            $users['D']->delete();
            $users['E']->delete();
        }
        if ($class === GenreSeeder::class) {
            Genre::whereIn('name', ['エッセイ', '芸術', '料理', '旅行'])->delete();
        }
        if ($class === BookSeeder::class) {
            $books[10]->delete();
            $books[11]->delete();
        }
        if ($class === BookSeeder::class || $class === DatabaseSeeder::class) {
            $books[1]->update(['title' => '保持する既存値']);
            $books[1]->genres()->sync(Genre::where('name', '芸術')->pluck('id')->all());
        }
        if ($class === FavoriteSeeder::class) {
            DB::table('favorites')->delete();
            $users['A']->favoriteBooks()->attach($books[11]);
        }
        if ($class === ReviewLikeSeeder::class || $class === DatabaseSeeder::class) {
            DB::table('review_likes')->delete();
            Review::orderBy('id')->firstOrFail()->likedByUsers()->attach($users['A']);
        }
        $before = $this->snapshot();
        $writes = 0;
        $this->observeQueries(function ($event) use ($table, &$writes) {
            if (str_starts_with(strtolower($event->sql), 'insert into `'.$table.'`') && ++$writes === 2) {
                throw new RuntimeException('テスト用の途中障害');
            }
        }, function () use ($class) {
            try {
                $this->seed($class);
                $this->fail('途中障害がCLI呼び出し元へ返りませんでした。');
            } catch (RuntimeException $exception) {
                $this->assertSame('テスト用の途中障害', $exception->getMessage());
            }
        });
        $this->assertSame(2, $writes);
        $this->assertSame($before, $this->snapshot());
    }
}

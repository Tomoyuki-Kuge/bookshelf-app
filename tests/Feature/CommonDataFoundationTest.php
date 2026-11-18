<?php

/**
 * 共通データ基盤の物理定義・関連・削除制約を実DBで検証する。
 */

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 共通データ基盤のT-1-1〜13をMySQL 8.4で検証する。
 */
class CommonDataFoundationTest extends TestCase
{
    private static bool $migrated = false;

    /**
     * testing専用DBを確認し、既存データを破棄せずテストを準備する。
     */
    protected function setUp(): void
    {
        parent::setUp();
        // 開発用DBへの書き込みを防ぎ、通常のmigrationだけで準備する。
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', DB::connection()->getDriverName());
        $this->assertSame('testing', DB::connection()->getDatabaseName());
        $this->assertSame('testing', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertStringStartsWith('8.4.', DB::selectOne('SELECT VERSION() AS version')->version);
        if (! self::$migrated) {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            self::$migrated = true;
        }
        // 各テストの追加・更新・削除だけを最後に取り消し、既存行を維持する。
        DB::beginTransaction();
        $this->beforeApplicationDestroyed(function () {
            DB::rollBack();
            Carbon::setTestNow();
        });
    }

    /**
     * 設計書に対応する業務列の型とNULL許可を返す。
     *
     * @return array<string, array<string, string>>
     */
    private function definition(): array
    {
        $id = ['id' => 'bigint unsigned'];
        $times = ['created_at' => 'timestamp?', 'updated_at' => 'timestamp?'];

        return [
            'users' => $id + ['name' => 'varchar(255)', 'email' => 'varchar(255)', 'email_verified_at' => 'timestamp?', 'password' => 'varchar(255)', 'remember_token' => 'varchar(100)?'] + $times,
            'books' => $id + ['user_id' => 'bigint unsigned', 'title' => 'varchar(255)', 'author' => 'varchar(255)', 'isbn' => 'varchar(13)', 'published_date' => 'date', 'image_url' => 'varchar(255)?', 'description' => 'text?'] + $times,
            'genres' => $id + ['name' => 'varchar(255)'] + $times,
            'book_genre' => $id + ['book_id' => 'bigint unsigned', 'genre_id' => 'bigint unsigned'] + $times,
            'reviews' => $id + ['user_id' => 'bigint unsigned', 'book_id' => 'bigint unsigned', 'rating' => 'int', 'comment' => 'text'] + $times,
            'favorites' => $id + ['user_id' => 'bigint unsigned', 'book_id' => 'bigint unsigned'] + $times,
            'review_likes' => $id + ['user_id' => 'bigint unsigned', 'review_id' => 'bigint unsigned'] + $times,
        ];
    }

    /**
     * 実DBから対象テーブルの列定義を取得する。
     */
    private function columns(string $table): array
    {
        return DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_KEY, EXTRA, DATETIME_PRECISION, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
    }

    /**
     * 利用者・書籍・ジャンル・レビューと各中間行を作成する。
     *
     * @return array{User, Book, Genre, Review}
     */
    private function graph(?User $owner = null, ?User $reviewer = null): array
    {
        $owner ??= User::factory()->create();
        $book = Book::factory()->for($owner)->create();
        $genre = Genre::factory()->create();
        $book->genres()->attach($genre);
        $review = Review::factory()->for($book)->for($reviewer ?? $owner)->create();
        $owner->favoriteBooks()->attach($book);
        $owner->likedReviews()->attach($review);

        return [$owner, $book, $genre, $review];
    }

    /**
     * このテストが作成した行だけを制約検証用に取得する。
     */
    private function fixtureRows(): array
    {
        [$user, $book, $genre, $review] = $this->graph();

        return [
            'users' => $user->getAttributes(),
            'books' => $book->getAttributes(),
            'genres' => $genre->getAttributes(),
            'reviews' => $review->getAttributes(),
            'book_genre' => (array) DB::table('book_genre')->where('book_id', $book->id)->where('genre_id', $genre->id)->first(),
            'favorites' => (array) DB::table('favorites')->where('user_id', $user->id)->where('book_id', $book->id)->first(),
            'review_likes' => (array) DB::table('review_likes')->where('user_id', $user->id)->where('review_id', $review->id)->first(),
        ];
    }

    /**
     * 操作が指定したMySQL制約エラーで拒否されることを確認する。
     */
    private function assertRejected(callable $operation, int $mysqlCode): void
    {
        try {
            $operation();
            $this->fail('DB制約に違反する操作が成功しました。');
        } catch (QueryException $exception) {
            $this->assertSame($mysqlCode, $exception->errorInfo[1]);
        }
    }

    /**
     * T-1-1：7業務表・44列の型、NULL許可、主キーと採番を検証する。
     */
    public function test_t_1_1_business_columns_match_physical_definition(): void
    {
        $count = 0;
        foreach ($this->definition() as $table => $expected) {
            $columns = $this->columns($table);
            $this->assertSame(array_keys($expected), array_column($columns, 'COLUMN_NAME'), $table);
            foreach ($columns as $column) {
                $type = $expected[$column->COLUMN_NAME];
                $this->assertSame(rtrim($type, '?'), $column->COLUMN_TYPE, "$table.$column->COLUMN_NAME");
                $this->assertSame(str_ends_with($type, '?') ? 'YES' : 'NO', $column->IS_NULLABLE);
                if ($column->COLUMN_NAME === 'id') {
                    $this->assertSame('PRI', $column->COLUMN_KEY);
                    $this->assertSame('auto_increment', $column->EXTRA);
                }
                $count++;
            }
        }
        $this->assertSame(44, $count);
    }

    /**
     * T-1-2：中間表の専用ID・組の一意性と派生値の非保存を検証する。
     */
    public function test_t_1_2_pivots_have_ids_and_unique_pairs_without_derived_columns(): void
    {
        foreach (['book_genre' => ['book_id', 'genre_id'], 'favorites' => ['user_id', 'book_id'], 'review_likes' => ['user_id', 'review_id']] as $table => $pair) {
            $indexes = collect(DB::select("SHOW INDEX FROM `$table`"))->where('Non_unique', 0)->groupBy('Key_name');
            $this->assertCount(2, $indexes);
            $this->assertSame(['id'], $indexes['PRIMARY']->pluck('Column_name')->all());
            $this->assertContains($pair, $indexes->map(fn ($index) => $index->pluck('Column_name')->all())->all());
        }
        foreach (['genre_id', 'average_rating', 'reviews_avg_rating', 'reviews_count', 'deleted_at'] as $column) {
            $this->assertFalse(Schema::hasColumn('books', $column));
        }
    }

    /**
     * T-1-3：モデル間の関連取得と中間行の日時管理を検証する。
     */
    public function test_t_1_3_all_model_relationships_and_pivot_timestamps_are_available(): void
    {
        [$user, $book, $genre, $review] = $this->graph();
        $this->assertTrue($book->user->is($user));
        $this->assertTrue($review->user->is($user));
        $this->assertTrue($review->book->is($book));
        foreach ([$user->books, $genre->books, $user->favoriteBooks] as $books) {
            $this->assertSame([$book->id], $books->modelKeys());
        }
        $this->assertSame([$review->id], $book->reviews->modelKeys());
        $this->assertSame([$review->id], $user->reviews->modelKeys());
        $this->assertSame([$review->id], $user->likedReviews->modelKeys());
        $this->assertSame([$user->id], $review->likedByUsers->modelKeys());
        $this->assertSame([$user->id], $book->favoritedByUsers->modelKeys());
        $this->assertSame([$genre->id], $book->genres->modelKeys());
        foreach ([$book->genres->first(), $genre->books->first(), $user->favoriteBooks->first(), $book->favoritedByUsers->first(), $user->likedReviews->first(), $review->likedByUsers->first()] as $related) {
            $this->assertNotNull($related->pivot->created_at);
            $this->assertNotNull($related->pivot->updated_at);
        }
    }

    /**
     * T-1-4：パスワードのハッシュ保存と認証列・非公開項目を検証する。
     */
    public function test_t_1_4_user_hashes_password_and_preserves_authentication_columns(): void
    {
        $user = new User(['name' => '確認利用者', 'email' => fake()->unique()->safeEmail(), 'password' => 'password', 'password_confirmation' => 'password']);
        $user->save();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
        $this->assertFalse(Schema::hasColumn('users', 'password_confirmation'));
        $this->assertTrue(Schema::hasColumns('users', ['email_verified_at', 'remember_token', 'password']));
        $this->assertArrayNotHasKey('password', $user->fresh()->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->fresh()->toArray());
    }

    /**
     * T-1-5：全外部キーと必須列が不正な参照・NULLを拒否することを検証する。
     */
    public function test_t_1_5_foreign_keys_and_all_required_columns_reject_invalid_values(): void
    {
        $this->assertSame(1, DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled);
        $rows = $this->fixtureRows();
        foreach (['books' => ['user_id'], 'book_genre' => ['book_id', 'genre_id'], 'reviews' => ['user_id', 'book_id'], 'favorites' => ['user_id', 'book_id'], 'review_likes' => ['user_id', 'review_id']] as $table => $references) {
            $row = $rows[$table];
            foreach ($references as $column) {
                $this->assertRejected(fn () => DB::table($table)->where('id', $row['id'])->update([$column => 999999999999]), 1452);
            }
        }
        foreach ($this->definition() as $table => $columns) {
            $row = $rows[$table];
            foreach ($columns as $column => $type) {
                if ($column !== 'id' && ! str_ends_with($type, '?')) {
                    $this->assertRejected(fn () => DB::table($table)->where('id', $row['id'])->update([$column => null]), 1048);
                }
            }
        }
        // 存在しない親の拒否に加え、削除・参照ID更新時の制約も照合する。
        $constraints = DB::select('SELECT DELETE_RULE, UPDATE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE()');
        $this->assertCount(9, $constraints);
        $this->assertSame(8, collect($constraints)->where('DELETE_RULE', 'CASCADE')->count());
        $this->assertSame(1, collect($constraints)->where('DELETE_RULE', 'RESTRICT')->count());
        $this->assertSame(['RESTRICT'], collect($constraints)->pluck('UPDATE_RULE')->unique()->values()->all());
    }

    /**
     * T-1-6：業務上の一意制約が重複行の保存を拒否することを検証する。
     */
    public function test_t_1_6_all_unique_constraints_reject_duplicates(): void
    {
        $rows = $this->fixtureRows();
        foreach (['users', 'books', 'genres', 'book_genre', 'favorites', 'review_likes'] as $table) {
            $row = $rows[$table];
            unset($row['id']);
            $this->assertRejected(fn () => DB::table($table)->insert($row), 1062);
        }
    }

    /**
     * T-1-7：同じ利用者が同じ書籍へ複数レビューを投稿できることを検証する。
     */
    public function test_t_1_7_same_user_can_review_the_same_book_multiple_times(): void
    {
        [$user, $book] = $this->graph();
        $second = Review::factory()->for($user)->for($book)->create();
        $this->assertSame(2, $book->reviews()->count());
        $this->assertNotNull($second->fresh());
    }

    /**
     * T-1-8：書籍削除が関連行だけへ連鎖し無関係なデータを残すことを検証する。
     */
    public function test_t_1_8_deleting_a_book_cascades_only_its_dependents(): void
    {
        [$user, $book, $genre, $review] = $this->graph();
        [$otherUser, $otherBook, $otherGenre, $otherReview] = $this->graph();
        $otherUser->favoriteBooks()->attach($book);
        $otherUser->likedReviews()->attach($review);
        $book->delete();
        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        foreach (['favorites', 'book_genre'] as $table) {
            $this->assertDatabaseMissing($table, ['book_id' => $book->id]);
        }
        $this->assertDatabaseMissing('review_likes', ['review_id' => $review->id]);
        $this->assertNotNull($user->fresh());
        $this->assertNotNull($genre->fresh());
        $this->assertGraphRemains($otherUser, $otherBook, $otherGenre, $otherReview);
    }

    /**
     * T-1-9：レビュー単独削除がそのいいねだけを削除することを検証する。
     */
    public function test_t_1_9_deleting_a_review_preserves_its_book_and_other_reviews(): void
    {
        [$user, $book, $genre, $review] = $this->graph();
        $other = Review::factory()->for($book)->for($user)->create();
        $user->likedReviews()->attach($other);
        $review->delete();
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('review_likes', ['review_id' => $review->id]);
        $this->assertGraphRemains($user, $book, $genre, $other);
    }

    /**
     * T-1-10：紐付きジャンルの削除拒否と紐付け解除後の削除を検証する。
     */
    public function test_t_1_10_linked_genre_deletion_is_restricted_without_changes(): void
    {
        [$user, $book, $genre] = $this->graph();
        $before = (array) DB::table('book_genre')->where('book_id', $book->id)->first();
        $this->assertRejected(fn () => $genre->delete(), 1451);
        $this->assertNotNull($genre->fresh());
        $this->assertNotNull($book->fresh());
        $this->assertSame($before, (array) DB::table('book_genre')->where('book_id', $book->id)->first());
        $book->genres()->detach($genre);
        $genre->delete();
        $this->assertDatabaseMissing('genres', ['id' => $genre->id]);
    }

    /**
     * T-1-11：利用者削除の連鎖範囲と無関係なデータの保持を検証する。
     */
    public function test_t_1_11_deleting_a_user_cascades_owned_and_participating_rows(): void
    {
        [$user, $book, $genre, $review] = $this->graph();
        [$otherUser, $otherBook, $otherGenre, $otherReview] = $this->graph();
        $crossReview = Review::factory()->for($otherBook)->for($user)->create();
        $otherUser->likedReviews()->attach($crossReview);
        $otherUsersReview = Review::factory()->for($book)->for($otherUser)->create();
        $otherUser->likedReviews()->attach($otherUsersReview);
        $user->favoriteBooks()->attach($otherBook);
        $otherUser->favoriteBooks()->attach($book);
        $user->likedReviews()->attach($otherReview);
        DB::table('users')->where('id', $user->id)->delete();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        foreach ([$review, $crossReview, $otherUsersReview] as $deletedReview) {
            $this->assertDatabaseMissing('reviews', ['id' => $deletedReview->id]);
            $this->assertDatabaseMissing('review_likes', ['review_id' => $deletedReview->id]);
        }
        $this->assertDatabaseMissing('favorites', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('book_genre', ['book_id' => $book->id]);
        foreach (['reviews', 'favorites', 'review_likes'] as $table) {
            $this->assertDatabaseMissing($table, ['user_id' => $user->id]);
        }
        $this->assertNotNull($genre->fresh());
        $this->assertGraphRemains($otherUser, $otherBook, $otherGenre, $otherReview);
    }

    /**
     * 削除対象と無関係なデータの値と関連行が維持されることを確認する。
     */
    private function assertGraphRemains(User $user, Book $book, Genre $genre, Review $review): void
    {
        foreach ([$user, $book, $genre, $review] as $model) {
            $before = $model->getAttributes();
            $after = $model->fresh()->getAttributes();
            ksort($before);
            ksort($after);
            $this->assertSame($before, $after);
        }
        $this->assertDatabaseHas('book_genre', ['book_id' => $book->id, 'genre_id' => $genre->id]);
        $this->assertDatabaseHas('favorites', ['user_id' => $user->id, 'book_id' => $book->id]);
        $this->assertDatabaseHas('review_likes', ['user_id' => $user->id, 'review_id' => $review->id]);
    }

    /**
     * T-1-12：ISBN・出版日の型、任意項目、ジャンル未登録と動的集計を検証する。
     */
    public function test_t_1_12_isbn_date_optional_fields_and_aggregates_are_preserved(): void
    {
        $book = Book::factory()->create(['isbn' => '0123456789012', 'published_date' => '2026-01-02']);
        $this->assertSame('0123456789012', $book->fresh()->isbn);
        $this->assertSame('2026-01-02', $book->fresh()->published_date);
        $this->assertNull($book->fresh()->description);
        $this->assertNull($book->fresh()->image_url);
        $this->assertSame(0, $book->genres()->count());
        $empty = Book::withAvg('reviews', 'rating')->withCount('reviews')->findOrFail($book->id);
        $this->assertNull($empty->reviews_avg_rating);
        $this->assertSame(0, $empty->reviews_count);
        Review::factory()->for($book)->create(['rating' => 2]);
        Review::factory()->for($book)->create(['rating' => 5]);
        $result = Book::withAvg('reviews', 'rating')->withCount('reviews')->findOrFail($book->id);
        $this->assertSame(3.5, (float) $result->reviews_avg_rating);
        $this->assertSame(2, $result->reviews_count);
    }

    /**
     * T-1-13：MySQLの物理条件、名前比較、UTC・秒精度と日時管理を検証する。
     */
    public function test_t_1_13_mysql_physical_contract_timestamps_and_name_comparison(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
        $this->assertSame('+00:00', DB::selectOne('SELECT @@session.time_zone AS zone')->zone);
        foreach ($this->definition() as $table => $expected) {
            $storage = DB::selectOne('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]);
            $this->assertSame('InnoDB', $storage->ENGINE);
            $this->assertSame('utf8mb4_unicode_ci', $storage->TABLE_COLLATION);
            foreach ($this->columns($table) as $column) {
                $this->assertNull($column->COLUMN_DEFAULT);
                if ($column->COLUMN_NAME !== 'id') {
                    $this->assertSame('', $column->EXTRA);
                }
                if (str_starts_with($expected[$column->COLUMN_NAME], 'timestamp')) {
                    $this->assertSame(0, $column->DATETIME_PRECISION);
                }
                if ($column->COLLATION_NAME !== null) {
                    $this->assertSame($table === 'genres' && $column->COLUMN_NAME === 'name' ? 'utf8mb4_bin' : 'utf8mb4_unicode_ci', $column->COLLATION_NAME);
                }
            }
        }
        foreach (['password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        // このテスト専用の名前を使い、既存testingデータとの衝突を避ける。
        $suffix = bin2hex(random_bytes(8));
        Genre::create(['name' => 'Science'.$suffix]);
        $this->assertRejected(fn () => Genre::create(['name' => 'SCIENCE'.$suffix]), 1062);
        $names = array_map(fn ($name) => $name.$suffix, ['ＡＢＣ', 'ABC', 'カナ', 'ｶﾅ']);
        $ids = array_map(fn ($name) => Genre::create(['name' => $name])->id, $names);
        $this->assertSame($names, Genre::whereIn('id', $ids)->orderBy('id')->pluck('name')->all());
        // マイクロ秒を含む時刻を固定し、モデルと中間行の秒精度保存を確認する。
        Carbon::setTestNow(Carbon::parse('2026-11-19 12:34:56.987654', 'UTC'));
        [$user, $book, $genre, $review] = $this->graph();
        foreach ([$user, $book, $genre, $review] as $model) {
            $this->assertSame('Y-m-d H:i:s', $model->getDateFormat());
            $this->assertSame('2026-11-19 12:34:56', $model->fresh()->getRawOriginal('created_at'));
            $this->assertSame('2026-11-19 12:34:56', $model->fresh()->getRawOriginal('updated_at'));
        }
        foreach (['book_genre', 'favorites', 'review_likes'] as $table) {
            $row = DB::table($table)->where($table === 'review_likes' ? 'review_id' : 'book_id', $table === 'review_likes' ? $review->id : $book->id)->where($table === 'book_genre' ? 'genre_id' : 'user_id', $table === 'book_genre' ? $genre->id : $user->id)->first();
            $this->assertSame('2026-11-19 12:34:56', $row->created_at);
            $this->assertSame('2026-11-19 12:34:56', $row->updated_at);
        }
        Carbon::setTestNow(Carbon::parse('2026-11-20 01:02:03.123456', 'UTC'));
        $book->update(['title' => '更新後']);
        $this->assertSame('2026-11-19 12:34:56', $book->fresh()->getRawOriginal('created_at'));
        $this->assertSame('2026-11-20 01:02:03', $book->fresh()->getRawOriginal('updated_at'));
        $user->favoriteBooks()->updateExistingPivot($book->id, []);
        $favorite = DB::table('favorites')->where('user_id', $user->id)->where('book_id', $book->id)->first();
        $this->assertSame('2026-11-19 12:34:56', $favorite->created_at);
        $this->assertSame('2026-11-20 01:02:03', $favorite->updated_at);
        DB::table('books')->where('id', $book->id)->update(['title' => 'DBから直接更新']);
        $this->assertSame('2026-11-20 01:02:03', $book->fresh()->getRawOriginal('updated_at'));
        $rawId = DB::table('genres')->insertGetId(['name' => '日時未指定'.$suffix]);
        $raw = DB::table('genres')->find($rawId);
        $this->assertNull($raw->created_at);
        $this->assertNull($raw->updated_at);
        $this->assertGreaterThan($genre->id, $rawId);
    }
}

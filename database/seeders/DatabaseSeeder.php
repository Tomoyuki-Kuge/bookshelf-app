<?php

/** 初期データのDatabaseSeederの責務を実行する。 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /** 依存順に投入し、途中失敗はこの実行全体を取り消す。 */
    public function run(): void
    {
        DB::transaction(function () {
            $this->call([UserSeeder::class, GenreSeeder::class, BookSeeder::class, ReviewSeeder::class, FavoriteSeeder::class, ReviewLikeSeeder::class]);
        });
    }
}

<?php

/** 初期データのGenreSeederの責務を実行する。 */

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GenreSeeder extends Seeder
{
    public const NAMES = ['小説', 'ビジネス', '技術書', '自己啓発', 'エッセイ', '歴史', '科学', '芸術', '料理', '旅行'];

    /** 共通ジャンルを重複させず投入する。 */
    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::NAMES as $name) {
                Genre::firstOrCreate(['name' => $name]);
            }
        });
    }
}

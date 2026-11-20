<?php

/** 初期データのFavoriteSeederの責務を実行する。 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FavoriteSeeder extends Seeder
{
    public const FAVORITES = ['A' => [1, 3, 6], 'B' => [2, 4, 8, 10], 'C' => [1, 3, 6, 9, 11], 'D' => [4, 5, 7], 'E' => [2, 7, 9, 11]];

    /** 指定組を追加し、既存のお気に入りも維持する。 */
    public function run(): void
    {
        DB::transaction(function () {
            [$users, $books] = SeedReferences::usersAndBooks(self::class);
            foreach (self::FAVORITES as $letter => $numbers) {
                $users[$letter]->favoriteBooks()->syncWithoutDetaching(array_map(fn ($number) => $books[$number]->id, $numbers));
            }
        });
    }
}

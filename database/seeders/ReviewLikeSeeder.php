<?php

/** 初期データのReviewLikeSeederの責務を実行する。 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ReviewLikeSeeder extends Seeder
{
    /** サンプルに一致するレビューへ、投稿者以外の固定組を追加する。 */
    public function run(): void
    {
        DB::transaction(function () {
            [$users, $books] = SeedReferences::usersAndBooks(self::class);
            $sampleReviewGroups = SeedReferences::reviewsForSamples(self::class, $users, $books);
            foreach ($sampleReviewGroups as $index => [$reviewAuthorKey, $reviews]) {
                $eligibleLikers = array_filter($users, fn ($userKey) => $userKey !== $reviewAuthorKey, ARRAY_FILTER_USE_KEY);
                $likerUserIds = array_map(fn ($user) => $user->id, array_slice(array_values($eligibleLikers), 0, $index % 4));
                foreach ($reviews as $review) {
                    $review->likedByUsers()->syncWithoutDetaching($likerUserIds);
                }
            }
        });
    }
}

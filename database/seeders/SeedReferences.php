<?php

/** 初期データの依存先を検査し自然キーから参照する。 */

namespace Database\Seeders;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use RuntimeException;

final class SeedReferences
{
    /** 書き込み前に依存不足を日本語の理由とともに通知する。 */
    public static function require(bool $condition, string $seeder, string $target): void
    {
        if (! $condition) {
            throw new RuntimeException($seeder.'：初期データの依存先が不足しています（'.$target.'）。');
        }
    }

    /** 利用者と書籍を自然キーで全件検査し、サンプル記号に対応付ける。 */
    public static function usersAndBooks(string $seeder): array
    {
        $users = [];
        foreach (UserSeeder::USERS as $index => [$name, $email]) {
            $user = User::where('email', $email)->first();
            self::require($user !== null, $seeder, '利用者 '.$email);
            $users[chr(65 + $index)] = $user;
        }
        $books = [];
        foreach (BookSeeder::BOOKS as $index => [, , $isbn]) {
            $book = Book::where('isbn', $isbn)->first();
            self::require($book !== null, $seeder, '書籍ISBN '.$isbn);
            $books[$index + 1] = $book;
        }

        return [$users, $books];
    }

    /** 同じサンプルを再投入したレビューも含め、全32パターンを先に検査する。 */
    public static function reviewsForSamples(string $seeder, array $users, array $books): array
    {
        $sampleReviewGroups = [];
        foreach (ReviewSeeder::REVIEWS as $index => [$bookNumber, $reviewAuthorKey, $rating, $comment]) {
            $reviews = Review::where('book_id', $books[$bookNumber]->id)->where('user_id', $users[$reviewAuthorKey]->id)
                ->where('rating', $rating)->where('comment', $comment)->get()
                // DBの照合順序で同値となる別本文をサンプルとして扱わない。
                ->filter(fn ($review) => $review->comment === $comment);
            self::require($reviews->isNotEmpty(), $seeder, 'レビューサンプル行 '.($index + 1));
            $sampleReviewGroups[] = [$reviewAuthorKey, $reviews];
        }

        return $sampleReviewGroups;
    }
}

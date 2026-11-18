<?php

/**
 * 検証用のレビューと投稿先・投稿者を生成する。
 */

namespace Database\Factories;

use App\Models\Book;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 検証用のレビューと投稿先・投稿者を生成する。
 */
class ReviewFactory extends Factory
{
    /**
     * レビューの関連先・評価・コメントの初期値を定義する。
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'book_id' => Book::factory(),
            'rating' => 4,
            'comment' => '参考になる一冊です。',
        ];
    }
}

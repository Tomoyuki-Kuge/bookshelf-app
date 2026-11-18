<?php

/**
 * 検証用の書籍と作成者を生成する。
 */

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 検証用の書籍と作成者を生成する。
 */
class BookFactory extends Factory
{
    /**
     * 書籍の必須項目と任意項目の初期値を定義する。
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'author' => fake()->name(),
            'isbn' => fake()->unique()->numerify('#############'),
            'published_date' => '2026-01-01',
            'description' => null,
            'image_url' => null,
        ];
    }
}

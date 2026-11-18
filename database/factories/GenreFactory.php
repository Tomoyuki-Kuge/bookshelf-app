<?php

/**
 * 検証用の共通ジャンルを生成する。
 */

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 検証用の共通ジャンルを生成する。
 */
class GenreFactory extends Factory
{
    /**
     * 既存データとの衝突を避けるジャンル名を定義する。
     */
    public function definition(): array
    {
        return [
            'name' => 'テストジャンル'.fake()->unique()->uuid(),
        ];
    }
}

<?php

/** 初期データのUserSeederの責務を実行する。 */

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public const USERS = [
        ['山田太郎', 'yamada@example.com'],
        ['鈴木花子', 'suzuki@example.com'],
        ['田中一郎', 'tanaka@example.com'],
        ['佐藤美咲', 'sato@example.com'],
        ['高橋健太', 'takahashi@example.com'],
    ];

    /** 既存の利用者情報を維持し、未登録の利用者だけを作る。 */
    public function run(): void
    {
        DB::transaction(function () {
            foreach (self::USERS as [$name, $email]) {
                User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('password')]);
            }
        });
    }
}

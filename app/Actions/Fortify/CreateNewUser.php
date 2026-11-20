<?php

/** Fortifyの登録契約に従い検証済みの利用者を作成する。 */

namespace App\Actions\Fortify;

use App\Models\User;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /** パスワードはUserモデルのhashed castで保存する。 */
    public function create(array $input): User
    {
        return User::create(['name' => $input['name'], 'email' => $input['email'], 'password' => $input['password']]);
    }
}

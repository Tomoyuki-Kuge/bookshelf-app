<?php

/** 登録入力をFormRequestで検証してFortifyへ渡す。 */

namespace App\Http\Controllers;

use App\Http\Requests\RegisterRequest;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\RegisterResponse;

class RegisteredUserController extends \Laravel\Fortify\Http\Controllers\RegisteredUserController
{
    /** FormRequestの検証後にFortifyの登録処理を呼ぶ。 */
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        return parent::store(app(RegisterRequest::class), $creator);
    }
}

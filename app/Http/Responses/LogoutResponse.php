<?php

/** Fortifyによるログアウト後にログイン画面へ移動する。 */

namespace App\Http\Responses;

class LogoutResponse implements \Laravel\Fortify\Contracts\LogoutResponse
{
    /** 認証処理後の遷移先を返す。 */
    public function toResponse($request)
    {
        return redirect('/login');
    }
}

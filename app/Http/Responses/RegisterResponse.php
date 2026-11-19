<?php

/** 登録成功後は元の保護ページにかかわらず一覧へ移動する。 */

namespace App\Http\Responses;

class RegisterResponse implements \Laravel\Fortify\Contracts\RegisterResponse
{
    /** 認証処理後の遷移先を返す。 */
    public function toResponse($request)
    {
        return redirect('/');
    }
}

<?php

/** 後続Issueで接続する業務経路の未実装状態を示す。 */

namespace App\Http\Controllers;

class PendingFeatureController extends Controller
{
    /** 後続Issueの処理が未実装であることを返す。 */
    public function __invoke(): never
    {
        abort(501);
    }
}

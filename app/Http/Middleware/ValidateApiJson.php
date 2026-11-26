<?php

/** 公開APIのJSON構文を正規化前に確認し、元のJSON型を保持する。 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class ValidateApiJson
{
    /** Web入力には介入せず、JSONの配列とオブジェクトを区別できる形で保存する。 */
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('api/*') && $request->isJson() && $request->getContent() !== '') {
            try {
                $request->attributes->set('original_api_json', json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR));
            } catch (JsonException) {
                throw new BadRequestHttpException('JSONの形式が正しくありません。');
            }
        }

        return $next($request);
    }
}

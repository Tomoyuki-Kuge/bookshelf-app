<?php

/** 障害応答と機密情報を含めない例外記録を扱う。 */

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * Determine whether an exception should be returned as JSON.
     *
     * @param  Request  $request
     */
    protected function shouldReturnJson($request, Throwable $e)
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /** APIだけに確定済みのエラー形式を適用し、HTTPヘッダーを維持する。 */
    public function render($request, Throwable $e)
    {
        if ($request->is('api/*')) {
            if ($e instanceof ValidationException) {
                return response()->json(['message' => '入力内容に誤りがあります。', 'errors' => $e->errors()], 422);
            }
            $prepared = $this->prepareException($e);
            if ($this->isHttpException($prepared)) {
                $status = $prepared->getStatusCode();
                $body = match ($status) {
                    400 => ['message' => 'JSONの形式が正しくありません。'],
                    404 => ['error' => '書籍が見つかりませんでした。'],
                    405 => ['message' => 'この操作方法には対応していません。'],
                    default => null,
                };
                if ($body !== null) {
                    return response()->json($body, $status, $prepared->getHeaders());
                }
            }
        }

        return parent::render($request, $e);
    }

    /** 想定内の応答を維持し、障害時だけデバッグ設定に依存しない安全な応答へ変える。 */
    protected function renderExceptionResponse($request, Throwable $e)
    {
        if ($this->isHttpException($e) && $e->getStatusCode() < 500) {
            return parent::renderExceptionResponse($request, $e);
        }

        $status = $this->isHttpException($e) ? $e->getStatusCode() : 500;
        $headers = $this->isHttpException($e) ? $e->getHeaders() : [];
        if ($this->shouldReturnJson($request, $e)) {
            return response()->json(['message' => '処理に失敗しました。時間をおいて再度お試しください。'], $status, $headers);
        }

        // 元の例外やpreviousを渡さず、Laravelの既定エラー画面だけを描画する。
        return parent::renderExceptionResponse($request, new HttpException($status, '', null, $headers));
    }

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /** 認証フォームでは名前とメールの文字列だけを旧入力へ戻す。 */
    protected function invalid($request, ValidationException $exception)
    {
        if ($request->is('login', 'register')) {
            $fields = $request->is('register') ? ['name', 'email'] : ['email'];
            $input = array_filter($request->only($fields), fn ($value) => is_string($value));

            return redirect($exception->redirectTo ?? url()->previous())
                ->withInput($input)
                ->withErrors($exception->errors(), $exception->errorBag);
        }

        return parent::invalid($request, $exception);
    }

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            // 例外本文・SQL・トレースには入力や認証情報が入り得るため記録しない。
            Log::error('アプリケーションで例外が発生しました。', [
                'occurred_at' => now()->toIso8601String(),
                'route' => request()->route()?->uri() ?? 'console',
                'exception_type' => get_class($e),
            ]);

            return false;
        });
    }
}

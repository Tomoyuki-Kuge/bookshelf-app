<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
            //
        });
    }
}

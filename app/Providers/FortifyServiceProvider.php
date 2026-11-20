<?php

/** Fortifyの認証契約と既存フォームへの接続を登録する。 */

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Requests\LoginRequest;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\LogoutResponse;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /** 認証契約と入力クラスの差し替えを登録する。 */
    public function register(): void
    {
        Fortify::ignoreRoutes();
        $this->app->bind(\Laravel\Fortify\Http\Requests\LoginRequest::class, LoginRequest::class);
        $this->app->singleton(RegisterResponse::class, \App\Http\Responses\RegisterResponse::class);
        $this->app->singleton(LogoutResponse::class, \App\Http\Responses\LogoutResponse::class);
    }

    /** 既存の認証画面と登録Actionを接続する。 */
    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::registerView(fn () => view('auth.register'));
    }
}

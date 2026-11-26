<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/';

    /**
     * Register the application's routes.
     */
    public function boot(): void
    {
        $this->routes(function () {
            Route::prefix('api')->middleware('api')->group(base_path('routes/api.php'));

            Route::get('/up', fn () => response('OK'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}

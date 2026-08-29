<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // Guests (login, public endpoints) are capped tighter than
        // authenticated accounts: one NAT'd IP must not starve its users.
        RateLimiter::for('api', function (Request $request) {
            if ($request->user() !== null) {
                return Limit::perMinute((int) config('pixflix.rate_limit.auth_per_minute', 240))
                    ->by('user:'.$request->user()->id);
            }

            return Limit::perMinute((int) config('pixflix.rate_limit.per_minute', 60))
                ->by('guest:'.$request->ip());
        });

        $this->routes(function () {
            Route::get('/up', [\App\Http\Controllers\HealthController::class, 'liveness'])
                ->name('health.liveness');

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}

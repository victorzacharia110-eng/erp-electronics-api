<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Request as RequestFacade;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tenant resolution helpers used by owner-scoped controllers.
        RequestFacade::macro('business', function () {
            return \App\Support\Tenant::activeBusiness($this);
        });

        RequestFacade::macro('ownerId', function () {
            return \App\Support\Tenant::ownerId($this);
        });
        // General API throttle applied to every /api request.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Login attempts: 5 per minute per IP (protects against brute force).
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Registration: 3 per minute and 10 per day per IP.
        RateLimiter::for('register', function (Request $request) {
            return [
                Limit::perMinute(3)->by($request->ip()),
                Limit::perDay(10)->by($request->ip()),
            ];
        });
    }
}

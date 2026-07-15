<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

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
        Vite::prefetch(concurrency: 3);

        // PIN/activation-code brute force protection for POS device auth
        // endpoints: limited per device_identifier+IP, and per IP overall so
        // one caller can't sidestep the first limit by rotating identifiers.
        RateLimiter::for('device-auth', function (Request $request) {
            return [
                Limit::perMinute(6)->by($request->ip().'|'.$request->input('device_identifier', 'unknown')),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });
    }
}

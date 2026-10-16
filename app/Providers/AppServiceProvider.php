<?php

namespace App\Providers;

use App\Observers\AuditObserver;
use App\Services\Audit\ServerAudit;
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
        // Server-side changes to master data and financial documents go
        // into the audit trail (Phase 12.2); device pushes are audited on
        // the device.
        foreach (ServerAudit::auditedModels() as $model) {
            $model::observe(AuditObserver::class);
        }

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

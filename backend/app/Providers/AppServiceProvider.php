<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('paymongo-webhook', function (Request $request) {
            return Limit::perMinute((int) config('services.paymongo.webhook_rate_limit', 120))
                ->by($request->ip() ?: 'unknown');
        });

        RateLimiter::for('iot-attendance', function (Request $request) {
            return Limit::perMinute((int) config('iot.attendance_rate_limit', 120))
                ->by(($request->header('X-Device-Id') ?: 'unknown').'|'.($request->ip() ?: 'unknown'));
        });
    }
}

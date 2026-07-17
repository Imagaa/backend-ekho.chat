<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Mutlak: Mencegah N+1 (Mati jika ada lazy loading)
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(! app()->isProduction());

        $this->configureRateLimiting();
    }

    protected function configureRateLimiting(): void
    {
        // 1. Identity-Based Login (Mencegah 1 kantor ke-banned jika 1 orang salah password)
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->input('email') ?: $request->ip());
        });

        // 2. Token-Based API (Limit berdasarkan User ID spesifik, bukan IP Publik)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(100)->by($request->user()?->id ?: $request->ip());
        });

        // 3. Webhook Shield (Mencegah DDoS ringan ke gerbang webhook Meta/Midtrans)
        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(200)->by($request->ip());
        });
    }
}
<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Tidak pakai shorthand channels: di atas — itu mendaftarkan /broadcasting/auth
    // dengan middleware default ['web'] (session-based), padahal auth di app ini murni
    // Bearer Personal Access Token (lihat AuthController::login()). Daftarkan manual
    // dengan guard sanctum stateless agar Echo bisa auth pakai header Authorization.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Tidak pakai statefulApi(): auth di sini murni Bearer Personal Access Token
        // (lihat AuthController::login()), bukan cookie-based SPA auth. statefulApi()
        // memaksa CSRF+session middleware untuk origin di SANCTUM_STATEFUL_DOMAINS,
        // yang menyebabkan "CSRF token mismatch" pada request dari frontend Next.js
        // yang tidak pernah mengambil /sanctum/csrf-cookie.

        // WAJIB kalau deploy di belakang reverse proxy/load balancer/CDN (Nginx,
        // Cloudflare, dsb) — tanpa ini, $request->ip(), deteksi HTTPS
        // ($request->isSecure()), dan URL yang di-generate bisa salah karena
        // Laravel menganggap proxy sebagai origin request, bukan client asli.
        // '*' = percaya semua proxy di depan app — aman SELAMA app tidak
        // diakses langsung tanpa lewat proxy tepercaya Anda. Kalau proxy Anda
        // punya IP/CIDR tetap (mis. Cloudflare), ganti '*' dengan daftar IP itu.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
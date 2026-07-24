<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    // FRONTEND_URL: custom domain final (diisi setelah domain dibeli).
    // 'https://*.vercel.app': wildcard bawaan Laravel — otomatis cover production default
    // domain (project.vercel.app) & semua preview deployment (project-hash-team.vercel.app)
    // tanpa perlu update .env tiap deploy.
    'allowed_origins' => array_values(array_filter([
        env('FRONTEND_URL', 'http://localhost:3000'),
        'https://*.vercel.app',
    ])),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true, // INI WAJIB TRUE UNTUK SANCTUM
];

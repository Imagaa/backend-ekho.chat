<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key'          => env('RESEND_API_KEY'),
        'from_address' => env('RESEND_FROM_ADDRESS', 'auth@ekho.imaga.site'),
    ],

    // Provider WABA: api.co.id (BSP resmi). Model reseller — 1 API key untuk
    // SEMUA tenant, BUKAN kredensial per-tenant. Lihat documentation.md §7.
    'apicoid' => [
        'base_url' => env('APICOID_BASE_URL', 'https://chat.api.co.id/api/v1/public'),
        'api_key' => env('APICOID_API_KEY'),
        'webhook_secret' => env('APICOID_WEBHOOK_SECRET'),
    ],

    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY'),
        'client_key' => env('MIDTRANS_CLIENT_KEY'),
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];

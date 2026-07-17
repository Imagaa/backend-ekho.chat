<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhook;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * Endpoint publik untuk menerima DLR (Delivery Receipt) dari api.co.id
     */
    public function handle(Request $request)
    {
        // 1. Verifikasi Token (Keamanan Dasar)
        $token = $request->query('verify_token') ?? $request->header('X-Verify-Token');
        
        if ($token !== env('WABA_WEBHOOK_VERIFY_TOKEN')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->all();

        // 2. Lempar ke Redis secepat mungkin (Non-blocking)
        // Disetel ke queue 'webhook' agar dieksekusi dengan prioritas tertinggi oleh worker
        ProcessWebhook::dispatch($payload)->onQueue('webhook');

        // 3. Return 200 OK langsung agar Meta/api.co.id tidak melakukan retry pengiriman webhook
        return response()->json(['status' => 'received'], 200);
    }
}
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
        // 0. Cegah Payload Raksasa (Maksimal 1MB)
        if (strlen($request->getContent()) > 1048576) {
            return response()->json(['error' => 'Payload too large'], 413);
        }

        // 1. Verifikasi HMAC-SHA256 (Standar Meta/WABA)
        // Dapatkan signature dari header (biasanya X-Hub-Signature-256)
        $signature = $request->header('X-Hub-Signature-256');
        
        if (!$signature) {
             return response()->json(['error' => 'Signature missing'], 401);
        }

        // Buat hash dari raw content dengan app_secret
        $computedSignature = hash_hmac('sha256', $request->getContent(), config('services.waba.app_secret'));

        // Gunakan hash_equals untuk keamanan timing attack
        if (!hash_equals('sha256=' . $computedSignature, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        $payload = $request->all();

        // 2. Lempar ke Redis secepat mungkin (Non-blocking)
        \App\Jobs\ProcessWebhook::dispatch($payload)->onQueue('webhook');

        // 3. Return 200 OK langsung
        return response()->json(['status' => 'received'], 200);
    }
}
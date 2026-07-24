<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWebhook;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    /**
     * Endpoint publik untuk menerima event dari api.co.id (message.received,
     * message.sent, message.delivered, message.read, message.failed).
     * Lihat documentation.md §7 untuk spesifikasi lengkap.
     */
    public function handle(Request $request)
    {
        // 0. Cegah Payload Raksasa (Maksimal 1MB)
        if (strlen($request->getContent()) > 1048576) {
            return response()->json(['error' => 'Payload too large'], 413);
        }

        // 1. Verifikasi HMAC-SHA256 — api.co.id: header X-Webhook-Signature,
        // hex polos TANPA prefix "sha256=" (BEDA dari skema Meta native).
        $signature = $request->header('X-Webhook-Signature');

        if (! $signature) {
            return response()->json(['error' => 'Signature missing'], 403);
        }

        $computedSignature = hash_hmac('sha256', $request->getContent(), config('services.apicoid.webhook_secret'));

        // hash_equals untuk mencegah timing attack
        if (! hash_equals($computedSignature, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 403);
        }

        $payload = $request->all();

        // 2. Lempar ke Redis secepat mungkin (Non-blocking)
        ProcessWebhook::dispatch($payload)->onQueue('webhook');

        // 3. Return 200 OK langsung — api.co.id minta respons < 5 detik
        return response()->json(['status' => 'received'], 200);
    }
}

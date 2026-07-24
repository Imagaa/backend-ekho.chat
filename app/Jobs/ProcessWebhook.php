<?php

namespace App\Jobs;

use App\Events\ChatReceived;
use App\Models\Chat;
use App\Models\MessageLog;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Payload api.co.id — FLAT per-event, BEDA dari Meta native. Lihat
 * documentation.md §7 untuk spesifikasi lengkap.
 *
 * {
 *   "event": "message.received"|"message.sent"|"message.delivered"|"message.read"|"message.failed",
 *   "timestamp": "...",
 *   "data": { "message_id", "customer_phone", "channel", "direction",
 *             "message_type", "content", "media_url", "phone_number_id", "business_phone" }
 * }
 *
 * ✅ CONFIRMED oleh api.co.id (2026-07-24): field routing di dalam "data" adalah
 * "phone_number_id" — BUKAN "whatsapp_phone_number_id" seperti asumsi awal kami.
 * Nilainya sama dengan whatsapp_phone_number_id yang dipakai saat kirim pesan,
 * jadi bisa langsung dicocokkan ke tenants.waba_phone_id tanpa lookup tambahan.
 * "business_phone" (nomor bisnis) juga tersedia di payload tapi tidak dipakai
 * untuk routing.
 *
 * Kalau field ini tidak ada / tenant tidak ketemu, job tetap SENGAJA berhenti
 * (fail-safe) alih-alih menebak — salah routing pesan masuk antar tenant adalah
 * kebocoran data yang serius.
 */
class ProcessWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $payload;

    // Retry maksimal jika terjadi deadlock database ringan
    public $tries = 3;

    public function __construct(array $payload)
    {
        $this->payload = $payload;
    }

    public function handle(): void
    {
        $event = $this->payload['event'] ?? null;
        $data = $this->payload['data'] ?? null;

        if (! $event || ! $data) {
            Log::warning('ProcessWebhook: payload tidak sesuai format api.co.id (event/data hilang).', $this->payload);
            return;
        }

        try {
            match (true) {
                $event === 'message.received' => $this->handleInboundMessage($data),
                in_array($event, ['message.sent', 'message.delivered', 'message.read', 'message.failed'], true)
                    => $this->handleStatusUpdate($event, $data),
                default => Log::info("ProcessWebhook: event '{$event}' belum ditangani.", $data),
            };
        } catch (\Exception $e) {
            Log::error('ProcessWebhook Error: ' . $e->getMessage(), ['event' => $event, 'data' => $data]);
            throw $e;
        }
    }

    private function handleInboundMessage(array $data): void
    {
        $customerPhone = $data['customer_phone'] ?? null;
        $messageId = $data['message_id'] ?? null;
        $content = $data['content'] ?? null;
        $phoneNumberId = $data['phone_number_id'] ?? null;

        if (! $customerPhone || ! $messageId || ! $content) {
            Log::warning('ProcessWebhook: field wajib hilang di message.received.', $data);
            return;
        }

        if (! $phoneNumberId) {
            // Fail-safe: TIDAK menebak tenant. Lihat catatan di atas class.
            Log::error('ProcessWebhook: phone_number_id tidak ada di payload — pesan tidak bisa di-routing ke tenant manapun.', $data);
            return;
        }

        $tenant = Tenant::where('waba_phone_id', $phoneNumberId)->first();

        if (! $tenant) {
            Log::error("ProcessWebhook: tidak ada tenant dengan waba_phone_id '{$phoneNumberId}'.", $data);
            return;
        }

        // firstOrCreate() untuk idempotency — api.co.id/Meta bisa kirim webhook duplikat
        $chat = Chat::firstOrCreate(
            ['message_id_meta' => $messageId],
            [
                'tenant_id'      => $tenant->id,
                'customer_phone' => $customerPhone,
                'message'        => $content,
                'direction'      => 'inbound',
            ]
        );

        // Broadcast HANYA jika pesan baru (bukan duplikat)
        if ($chat->wasRecentlyCreated) {
            broadcast(new ChatReceived($chat))->toOthers();
        }
    }

    private function handleStatusUpdate(string $event, array $data): void
    {
        $messageId = $data['message_id'] ?? null;

        if (! $messageId) {
            Log::warning("ProcessWebhook: message_id hilang di event '{$event}'.", $data);
            return;
        }

        // "message.delivered" -> "DELIVERED", "message.failed" -> "FAILED", dst.
        $status = strtoupper(str_replace('message.', '', $event));
        $errorReason = $status === 'FAILED' ? ($data['error']['message'] ?? null) : null;

        $log = MessageLog::where('message_id_meta', $messageId)->first();

        if ($log) {
            $log->update(['status' => $status, 'error_reason' => $errorReason]);
        }
    }
}

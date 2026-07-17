<?php

namespace App\Jobs;

use App\Models\MessageLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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
        try {
            // Asumsi struktur payload dari api.co.id (sesuaikan dengan dokumentasi provider)
            $entry = $this->payload['entry'][0]['changes'][0]['value'] ?? null;

            if (!$entry || !isset($entry['statuses'])) {
                return; // Bukan webhook status pesan, abaikan.
            }

            $statusData = $entry['statuses'][0];
            $messageIdMeta = $statusData['id'];
            $status = strtoupper($statusData['status']); // SENT, DELIVERED, READ, FAILED
            $errorReason = null;

            if ($status === 'FAILED' && isset($statusData['errors'])) {
                $errorReason = $statusData['errors'][0]['title'] ?? 'Unknown Error';
            }

            // Cari log pesan berdasarkan ID Meta
            $log = MessageLog::where('message_id_meta', $messageIdMeta)->first();

            if ($log) {
                $log->update([
                    'status' => $status,
                    'error_reason' => $errorReason
                ]);
            }

        } catch (\Exception $e) {
            // Catat error fatal di storage/logs/laravel.log tanpa mematikan worker
            Log::error('Webhook Processing Error: ' . $e->getMessage());
            
            // Lempar kembali exception agar Job terhitung gagal dan masuk ke antrean retry
            throw $e;
        }
    }
}
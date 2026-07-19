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
            $entry = $this->payload['entry'][0]['changes'][0]['value'] ?? null;
            if (!$entry) return;

            // --- 1. HANDLING STATUS DLR (Delivery Receipts) ---
            if (isset($entry['statuses'])) {
                $statusData = $entry['statuses'][0];
                $messageIdMeta = $statusData['id'];
                $status = strtoupper($statusData['status']);
                $errorReason = ($status === 'FAILED' && isset($statusData['errors'])) 
                                ? $statusData['errors'][0]['title'] 
                                : null;

                $log = MessageLog::where('message_id_meta', $messageIdMeta)->first();
                if ($log) {
                    $log->update(['status' => $status, 'error_reason' => $errorReason]);
                }
            }

            // --- 2. HANDLING INBOUND CHAT (Pesan Masuk dari Customer) ---
            if (isset($entry['messages'])) {
                $messageData = $entry['messages'][0];
                $contactData = $entry['contacts'][0] ?? null;
                
                // Cari nomor telepon customer dari payload WABA
                $customerPhone = $contactData['wa_id'] ?? $messageData['from'] ?? null;
                $metadata = $entry['metadata'];
                
                // Cari Tenant berdasarkan waba_phone_id
                $tenant = \App\Models\Tenant::where('waba_phone_id', $metadata['phone_number_id'])->first();

                if ($tenant && $customerPhone && isset($messageData['text']['body'])) {
                    
                    // Patch 3B: firstOrCreate() untuk idempotency
                    // Meta sering mengirim webhook duplikat. firstOrCreate() mencegah
                    // duplicate entry exception yang akan crash worker & retry 3x sia-sia.
                    $chat = \App\Models\Chat::firstOrCreate(
                        ['message_id_meta' => $messageData['id']],  // search key (UNIQUE)
                        [
                            'tenant_id'      => $tenant->id,
                            'customer_phone' => $customerPhone,
                            'message'        => $messageData['text']['body'],
                            'direction'      => 'inbound',
                        ]
                    );

                    // Broadcast HANYA jika pesan baru (bukan duplikat dari Meta)
                    if ($chat->wasRecentlyCreated) {
                        broadcast(new \App\Events\ChatReceived($chat))->toOthers();
                    }
                }
            }

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Webhook Error: ' . $e->getMessage());
            throw $e;
        }
    }
}
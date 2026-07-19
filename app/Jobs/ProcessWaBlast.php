<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\MessageLog;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class ProcessWaBlast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $log;
    public $dynamicData;

    // Batasi maksimal 3 kali percobaan jika API Meta/Provider down
    public $tries = 3;
    
    // Jeda antar percobaan (10 detik, 30 detik, lalu 60 detik) untuk memberi waktu server pulih
    public $backoff = [10, 30, 60];

    public function __construct(MessageLog $log, array $dynamicData = [])
    {
        $this->log = $log;
        $this->dynamicData = $dynamicData;
    }

    public function handle(): void
    {
        $tenant = Tenant::find($this->log->tenant_id);
        
        // 1. Redis Rate Limiting (Mencegah banned Meta)
        // Format: 'wa_blast_tenant_1', max 50 request per 1 detik
        Redis::throttle('wa_blast_tenant_' . $tenant->id)
            ->allow(50)->every(1)
            ->then(function () use ($tenant) {
                
                // 2. Eksekusi Blast ke API
                $this->sendToWaba($tenant);

            }, function () {
                // Jika melebihi limit, kembalikan ke queue dengan jeda 5 detik
                $this->release(5);
            });
    }

    private function sendToWaba(Tenant $tenant)
    {
        $campaign = Campaign::with('template')->find($this->log->campaign_id);
        
        if (!$campaign || !$tenant->waba_api_key) {
            $this->markFailed('Konfigurasi Tenant/Campaign tidak valid.');
            return;
        }

        // 3. Potong Saldo dengan Pessimistic Locking
        // Asumsi harga per template Rp 500 (Bisa dinamis tergantung kategori nanti)
        $costPerMessage = 500; 

        try {
            $tenant->wallet->deductBalance($costPerMessage);
        } catch (\Exception $e) {
            $this->markFailed('Saldo tidak mencukupi. Terhenti oleh sistem pengunci.');
            return;
        }

        // 4. Susun Payload & Ganti Placeholder (Contoh sederhana)
        $components = $campaign->template->components;
        // Logic penggantian string placeholder {{1}}, {{2}} dengan array $this->dynamicData akan disisipkan di sini nantinya.

        // 5. HTTP Request ke api.co.id dengan Strict Timeout
        $response = Http::timeout(10)->withHeaders([
            'Authorization' => 'Bearer ' . $tenant->waba_api_key,
            'Content-Type' => 'application/json',
        ])->post(env('WABA_BASE_URL') . '/v1/messages', [
            'messaging_product' => 'whatsapp',
            'to' => $this->log->recipient_phone,
            'type' => 'template',
            'template' => [
                'name' => $campaign->template->name,
                'language' => ['code' => $campaign->template->language],
                'components' => $components
            ]
        ]);

        if ($response->successful()) {
            $this->log->update([
                'status' => 'SENT',
                'message_id_meta' => $response->json('messages.0.id') // Tangkap ID dari Meta untuk dicocokkan dengan webhook
            ]);
        } else {
            // Jika api.co.id menolak (misal nomor diblokir), saldo TIDAK dikembalikan otomatis di sini. 
            // Saldo dikembalikan melalui proses rekonsiliasi atau kebijakan perusahaan Anda.
            $this->markFailed($response->body());
        }
    }

    private function markFailed($reason)
    {
        $this->log->update([
            'status' => 'FAILED',
            'error_reason' => substr($reason, 0, 250) // Batasi panjang error
        ]);
    }
}
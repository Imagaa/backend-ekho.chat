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

        // 1. Redis Rate Limiting — GLOBAL (bukan per-tenant), karena limit
        // api.co.id di level 1 akun master yang dipakai SEMUA tenant sekaligus.
        // 60 pesan/menit dari mereka ≈ 1/detik — throttle halus per detik supaya
        // tidak bursty (hindari 60 sekaligus lalu diam 59 detik).
        Redis::throttle('apicoid_send_rate_limit')
            ->allow(1)->every(1)
            ->then(function () use ($tenant) {

                // 2. Eksekusi Blast ke API
                $this->sendToWaba($tenant);

            }, function () {
                // Jika melebihi limit, kembalikan ke queue dengan jeda 1 detik
                $this->release(1);
            });
    }

    private function sendToWaba(Tenant $tenant)
    {
        $campaign = Campaign::with('template')->find($this->log->campaign_id);

        if (!$campaign || !$tenant->waba_phone_id) {
            $this->markFailed('Konfigurasi Tenant/Campaign tidak valid (nomor WhatsApp belum aktif).');
            return;
        }

                // 3. Saldo TIDAK dipotong di sini — dipotong sekali di ProcessBlastCampaign (orchestrator)
        // Ini mencegah lockForUpdate dipanggil N kali untuk N kontak dalam satu campaign

        // 4. Susun Payload & Ganti Placeholder {{1}}, {{2}}, dst. dari dynamic_data kontak
        $components = collect($campaign->template->components)->map(function ($component) {
            if (isset($component['parameters'])) {
                $component['parameters'] = collect($component['parameters'])
                    ->map(function ($param) {
                        if ($param['type'] === 'text' && isset($param['placeholder_key'])) {
                            $param['text'] = $this->dynamicData[$param['placeholder_key']] ?? ($param['text'] ?? '');
                            unset($param['placeholder_key']);
                        }
                        return $param;
                    })->all();
            }
            return $component;
        })->all();

        // 5. HTTP Request ke api.co.id — 1 API key level-aplikasi, nomor
        // pengirim dibedakan lewat whatsapp_phone_number_id milik tenant.
        $response = Http::timeout(10)->withToken(config('services.apicoid.api_key'))
            ->post(config('services.apicoid.base_url') . '/messages/send', [
            'phone_number' => $this->log->recipient_phone,
            'channel' => 'whatsapp',
            'message_type' => 'template',
            'whatsapp_phone_number_id' => $tenant->waba_phone_id,
            'template' => [
                'name' => $campaign->template->name,
                'language' => ['code' => $campaign->template->language],
                'components' => $components
            ]
        ]);

        if ($response->successful() && $response->json('success')) {
            $this->log->update([
                'status' => 'SENT',
                'message_id_meta' => $response->json('data.message_id'), // Dicocokkan dengan webhook masuk
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
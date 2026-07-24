<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Contact;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessBlastCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // Jangan retry di level ini — kegagalan pre-flight harus eksplisit
    public int $timeout = 300;

    public function __construct(public readonly int $campaignId) {}

    public function handle(): void
    {
        // Anti-N+1: eager load semua relasi yang dibutuhkan sekaligus
        $campaign = Campaign::with(['template', 'group.contacts', 'tenant.wallet'])
            ->findOrFail($this->campaignId);

        // Guard: jangan proses jika sudah tidak PENDING (misal: duplikat dispatch)
        if ($campaign->status !== 'PENDING') {
            Log::warning("Campaign #{$this->campaignId} dilewati, status: {$campaign->status}");
            return;
        }

        $contacts = $campaign->group->contacts;

        if ($contacts->isEmpty()) {
            $campaign->update(['status' => 'FAILED']);
            Log::error("Campaign #{$this->campaignId} gagal: contact group kosong.");
            return;
        }

        // --- PRE-FLIGHT BALANCE CHECK ---
        // Kalkulasi biaya: harga per kategori template (sesuai pricing Meta)
        $pricePerMessage = $this->getPriceByCategory($campaign->template->category);
        $totalCost       = $contacts->count() * $pricePerMessage;

        $wallet = $campaign->tenant->wallet;

        if ($wallet->balance < $totalCost) {
            $campaign->update(['status' => 'FAILED']);
            Log::error("Campaign #{$this->campaignId} gagal: saldo tidak cukup.", [
                'balance'    => $wallet->balance,
                'total_cost' => $totalCost,
            ]);
            return;
        }

        // --- DEDUCT SALDO (Pessimistic Locking — dari Wallet::deductBalance) ---
        try {
            $wallet->deductBalance($totalCost);
        } catch (\Exception $e) {
            $campaign->update(['status' => 'FAILED']);
            Log::error("Campaign #{$this->campaignId} gagal deduct saldo: " . $e->getMessage());
            return;
        }

        // Update campaign ke PROCESSING + catat total cost final
        $campaign->update([
            'status'          => 'PROCESSING',
            'total_contacts'  => $contacts->count(),
            'total_cost'      => $totalCost,
        ]);

        // --- DISPATCH SATU JOB PER KONTAK KE queue:blast DENGAN RATE LIMITING ---
        // Throttle aktual (1 pesan/detik, GLOBAL per akun) ada di ProcessWaBlast::handle()
        // — sesuai limit api.co.id 60 pesan/menit per akun master. Lihat documentation.md §7.
        foreach ($contacts as $contact) {
            $log = \App\Models\MessageLog::create([
                'tenant_id'      => $campaign->tenant_id,
                'campaign_id'    => $campaign->id,
                'contact_id'     => $contact->id,
                'recipient_phone'=> $contact->phone,
                'status'         => 'QUEUED',
            ]);

            ProcessWaBlast::dispatch($log, $contact->dynamic_data ?? [])
                ->onQueue('blast');
        }

        Log::info("Campaign #{$this->campaignId} dispatched {$contacts->count()} pesan ke queue:blast.");
    }

    /**
     * Harga per kategori template Meta (dalam Rupiah).
     * Sesuaikan dengan pricing aktual di akun WABA tenant.
     */
    private function getPriceByCategory(string $category): float
    {
        return match (strtoupper($category)) {
            'MARKETING'      => 500.00,
            'UTILITY'        => 200.00,
            'AUTHENTICATION' => 300.00,
            default          => 500.00,
        };
    }

    public function failed(\Throwable $e): void
    {
        Campaign::where('id', $this->campaignId)->update(['status' => 'FAILED']);
        Log::error("ProcessBlastCampaign #{$this->campaignId} unexpected failure: " . $e->getMessage());
    }
}
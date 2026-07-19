<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Campaign;
use App\Models\Contact;
use App\Models\MessageLog;
use App\Jobs\ProcessWaBlast;
use Illuminate\Support\Facades\DB;

class DispatchScheduledCampaigns extends Command
{
    protected $signature = 'campaign:dispatch';
    protected $description = 'Menjalankan campaign yang sudah masuk jadwal';

    public function handle()
    {
        $campaigns = Campaign::where('status', 'PENDING')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($campaigns as $campaign) {
            $campaign->update(['status' => 'PROCESSING']);

            // Mencegah memory leak dengan chunking
            Contact::where('contact_group_id', $campaign->contact_group_id)
                ->chunkById(500, function ($contacts) use ($campaign) {
                    
                    // Transaksi memastikan 500 query INSERT dieksekusi secepat kilat (Fraksi detik)
                    DB::transaction(function () use ($contacts, $campaign) {
                        foreach ($contacts as $contact) {
                            $log = MessageLog::create([
                                'tenant_id' => $campaign->tenant_id,
                                'campaign_id' => $campaign->id,
                                'contact_id' => $contact->id,
                                'recipient_phone' => $contact->phone,
                                'status' => 'QUEUED',
                            ]);
                            
                            // Lempar antrean seketika tanpa perlu query ulang (Anti N+1)
                            ProcessWaBlast::dispatch($log, $contact->dynamic_data ?? [])->onQueue('blast');
                        }
                    });
                    
                });
        }
    }
}
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AggregateMessageStats extends Command
{
    protected $signature = 'stats:aggregate {--date= : Tanggal spesifik YYYY-MM-DD}';
    protected $description = 'Agregasi status pesan harian ke tabel daily_message_stats';

    public function handle()
    {
        // Default ke hari ini jika tidak ada parameter
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::today();
        $dateString = $date->toDateString();

        $this->info("Memulai agregasi untuk tanggal: {$dateString}");

        // Query agregasi raw yang sangat efisien dari PostgreSQL
        $stats = DB::table('message_logs')
            ->select('tenant_id', 'status', DB::raw('count(*) as total'))
            ->whereDate('created_at', $dateString)
            ->groupBy('tenant_id', 'status')
            ->get();

        $processed = [];

        foreach ($stats as $stat) {
            $processed[$stat->tenant_id][strtolower($stat->status)] = $stat->total;
        }

        foreach ($processed as $tenantId => $counts) {
            DB::table('daily_message_stats')->updateOrInsert(
                ['tenant_id' => $tenantId, 'date' => $dateString],
                [
                    'total_sent' => $counts['sent'] ?? 0,
                    'total_delivered' => $counts['delivered'] ?? 0,
                    'total_read' => $counts['read'] ?? 0,
                    'total_failed' => $counts['failed'] ?? 0,
                    'updated_at' => now()
                ]
            );
        }

        $this->info("Agregasi selesai.");
    }
}
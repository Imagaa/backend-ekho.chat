<?php

namespace App\Console\Commands;

use App\Models\ContactImport;
use Illuminate\Console\Command;

class CleanupExpiredContactImports extends Command
{
    protected $signature = 'contacts:cleanup-imports';
    protected $description = 'Hapus riwayat import kontak (retention_policy=auto) yang sudah melewati expires_at';

    public function handle()
    {
        $deleted = ContactImport::where('retention_policy', 'auto')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        $this->info("Menghapus {$deleted} riwayat import kontak yang sudah kedaluwarsa.");
    }
}

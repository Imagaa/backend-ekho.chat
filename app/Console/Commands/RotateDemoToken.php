<?php

namespace App\Console\Commands;

use App\Services\DemoTokenService;
use Illuminate\Console\Command;

class RotateDemoToken extends Command
{
    protected $signature = 'demo:rotate-token';
    protected $description = 'Shuffle token rotating dashboard demo (dijadwalkan tiap 5 jam)';

    public function handle(DemoTokenService $service): void
    {
        $token = $service->rotate();
        $this->info("Token demo rotating diperbarui. Berlaku sampai {$service->rotatingExpiresAt()->toDateTimeString()}.");
    }
}

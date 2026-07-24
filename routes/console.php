<?php

use Illuminate\Support\Facades\Schedule;

// Agregasi chart per jam
Schedule::command('stats:aggregate')->hourly();

// Pengecekan jadwal blast setiap menit
Schedule::command('campaign:dispatch')->everyMinute();

// Hapus riwayat import kontak yang retention_policy=auto dan sudah kedaluwarsa
Schedule::command('contacts:cleanup-imports')->daily();

// Shuffle token rotating dashboard demo tiap 5 jam
Schedule::command('demo:rotate-token')->cron('0 */5 * * *');
<?php

use Illuminate\Support\Facades\Schedule;

// Agregasi chart per jam
Schedule::command('stats:aggregate')->hourly();

// Pengecekan jadwal blast setiap menit
Schedule::command('campaign:dispatch')->everyMinute();
<?php

return [
    // Subdomain khusus panel Superadmin (mis. admin.ekho.imaga.site). Kosong =
    // panel jalan di domain manapun — dipakai untuk local dev. WAJIB diisi di
    // production untuk membatasi akses panel ke subdomain tersebut saja.
    //
    // Dibaca lewat config() (bukan env() langsung di provider) supaya tetap
    // berfungsi benar setelah `php artisan config:cache` — env() di luar
    // file config/ akan selalu return null saat config sudah di-cache.
    'domain' => env('ADMIN_DOMAIN'),
];

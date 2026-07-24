<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model bisnis Ekho: 1 akun master api.co.id (1 API key untuk SEMUA tenant),
 * bukan kredensial WABA per-tenant. Lihat documentation.md §7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['waba_api_key', 'waba_endpoint']);
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('waba_api_key')->nullable();
            $table->string('waba_endpoint')->nullable();
        });
    }
};

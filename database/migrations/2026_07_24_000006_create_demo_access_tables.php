<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Realm DEMO — terisolasi total dari tenant (`users`) maupun superadmin
 * (`admin_users`). Token di sini HANYA membuka tampilan dashboard demo yang
 * datanya 100% dummy di frontend; TIDAK pernah memberi akses ke data/API
 * tenant sungguhan. Lihat AGENTS.md §DEMO DASHBOARD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 16)->unique();
            $table->string('type')->default('rotating'); // rotating | permanent
            $table->string('label')->nullable();          // catatan untuk token permanent
            $table->boolean('is_revoked')->default(false); // hanya relevan untuk permanent
            $table->timestamp('rotated_at')->nullable();   // kapan token rotating terakhir di-generate
            $table->unsignedBigInteger('created_by')->nullable(); // admin_users.id (permanent)
            $table->timestamps();

            $table->index(['type', 'is_revoked']);
        });

        Schema::create('demo_allowed_emails', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('label')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // Seed email default demo
        DB::table('demo_allowed_emails')->insert([
            'email'      => 'dev-demo@ekho.chat',
            'label'      => 'Email demo default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed satu token rotating awal supaya sistem langsung fungsional.
        // Karakter ambigu (0/O/1/I) dibuang agar mudah dibacakan saat presentasi.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $token = '';
        for ($i = 0; $i < 8; $i++) {
            $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        DB::table('demo_access_tokens')->insert([
            'token'      => $token,
            'type'       => 'rotating',
            'rotated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_allowed_emails');
        Schema::dropIfExists('demo_access_tokens');
    }
};

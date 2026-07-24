<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Realm auth terpisah total dari tenant (`users`). JANGAN tambahkan
     * tenant_id atau relasi apapun ke tabel tenant di sini — lihat
     * AGENTS.md §SUPERADMIN DASHBOARD.
     */
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');

            // TOTP (Filament built-in AppAuthentication) — wajib aktif, tidak bisa dilewati dari UI
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            // Jejak siapa membuat admin ini — self-referential, tanpa self-registration
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();

            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('admin_password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_password_reset_tokens');
        Schema::dropIfExists('admin_users');
    }
};

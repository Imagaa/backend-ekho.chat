<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Field tambahan untuk alur create+submit template ke api.co.id.
 * apicoid_template_id = ID internal api.co.id (dipakai untuk submit & cek status).
 * meta_template_id (sudah ada) = ID Meta, baru terisi setelah submit berhasil.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('apicoid_template_id')->nullable()->after('meta_template_id');
            $table->text('rejection_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['apicoid_template_id', 'rejection_reason']);
        });
    }
};

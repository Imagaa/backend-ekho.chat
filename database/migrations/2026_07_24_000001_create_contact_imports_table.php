<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path')->nullable(); // Dihapus (null) setelah job selesai
            $table->string('status')->default('PENDING'); // PENDING, PROCESSING, COMPLETED, FAILED
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->text('error_message')->nullable();

            // Retensi: keep (permanen), auto (dihapus otomatis), manual (dihapus manual oleh user)
            $table->string('retention_policy')->default('manual');
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index('expires_at'); // Dipakai scheduled cleanup command
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_imports');
    }
};

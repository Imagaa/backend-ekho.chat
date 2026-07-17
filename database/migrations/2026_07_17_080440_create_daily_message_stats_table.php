<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_message_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->integer('total_sent')->default(0);
            $table->integer('total_delivered')->default(0);
            $table->integer('total_read')->default(0);
            $table->integer('total_failed')->default(0);
            $table->unique(['tenant_id', 'date']); // Mencegah duplikasi data per hari
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_message_stats');
    }
};

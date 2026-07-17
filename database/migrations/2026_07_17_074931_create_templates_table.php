<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('meta_template_id')->nullable(); // ID dari webhook meta
            $table->string('name');
            $table->string('category'); // MARKETING, UTILITY, AUTHENTICATION
            $table->string('language')->default('id');
            $table->json('components'); // Array komponen header, body, footer, button
            $table->string('status')->default('PENDING'); // PENDING, APPROVED, REJECTED
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};

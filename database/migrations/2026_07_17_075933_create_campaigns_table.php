<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamp('scheduled_at')->nullable();
            $table->string('status')->default('PENDING'); // PENDING, PROCESSING, COMPLETED, FAILED
            $table->integer('total_contacts')->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};

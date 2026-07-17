<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->index('waba_phone_id');
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->index('message_id_meta');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->index('message_id_meta');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->index('scheduled_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['waba_phone_id']);
        });

        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropIndex(['message_id_meta']);
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->dropIndex(['message_id_meta']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropIndex(['status']);
        });
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per MCP tool call or operator REST request, so a restaurant
     * can see what its connected assistant did (the "Recent activity" list
     * on the AI assistant page). Refused calls are logged too, with the
     * reason. Arguments are stored redacted (no image payloads).
     */
    public function up(): void
    {
        Schema::create('api_call_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restaurant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel', 8);
            $table->string('action', 80);
            $table->json('arguments')->nullable();
            $table->boolean('ok');
            $table->string('error', 500)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->timestamp('created_at');

            $table->index(['restaurant_id', 'id']);
            $table->index(['api_key_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_call_logs');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('pos_provider')->nullable();
            $table->string('pos_ticket_id')->nullable();
            $table->timestamp('pos_pushed_at')->nullable();
            $table->timestamp('pos_push_failed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['pos_provider', 'pos_ticket_id', 'pos_pushed_at', 'pos_push_failed_at']);
        });
    }
};

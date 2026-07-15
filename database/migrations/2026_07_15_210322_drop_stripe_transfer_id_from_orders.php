<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `stripe_transfer_id` was scaffolded for a destination-charge model that
     * was never built — Plateful uses direct charges on the connected account
     * with an application fee, so no Transfer object ever exists. The column
     * was never written or read.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('stripe_transfer_id');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('stripe_transfer_id')->nullable();
        });
    }
};

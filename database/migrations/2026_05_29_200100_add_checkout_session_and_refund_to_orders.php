<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Link an order back to the Stripe Checkout Session that paid for it
     * (unique → idempotent materialization) and track refunds issued on
     * cancellation.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('stripe_checkout_session_id')->nullable()->unique()->after('stripe_charge_id');
            $table->timestamp('refunded_at')->nullable()->after('placed_at');
            $table->integer('refunded_cents')->default(0)->after('refunded_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['stripe_checkout_session_id']);
            $table->dropColumn(['stripe_checkout_session_id', 'refunded_at', 'refunded_cents']);
        });
    }
};

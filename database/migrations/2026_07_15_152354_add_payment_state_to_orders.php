<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            // `captured` is the right backfill for every existing order: until
            // now an order could not exist unless Stripe had already taken the
            // money. Only courier-network deliveries are authorized first.
            $table->string('payment_state')->default('captured')->after('stripe_charge_id');

            // When the hold was placed. Not just an audit trail — a card
            // authorization does not live forever (~7 days), so anything still
            // authorized has a clock on it, and this is where that clock reads.
            $table->timestamp('authorized_at')->nullable()->after('payment_state');
            $table->timestamp('captured_at')->nullable()->after('authorized_at');
            $table->timestamp('voided_at')->nullable()->after('captured_at');

            // Finds orders stuck holding a customer's funds. Narrow because
            // only a courier-network delivery is ever anything but `captured`.
            $table->index(['payment_state', 'authorized_at']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['payment_state', 'authorized_at']);
            $table->dropColumn(['payment_state', 'authorized_at', 'captured_at', 'voided_at']);
        });
    }
};

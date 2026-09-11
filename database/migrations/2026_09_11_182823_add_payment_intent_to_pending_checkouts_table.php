<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The app pays with a PaymentIntent instead of a hosted Checkout
     * Session, so a pending checkout can be keyed by either. Orders get a
     * partial unique index on the intent id — the idempotency backstop for
     * the confirm-endpoint + webhook race, mirroring the session-id unique.
     */
    public function up(): void
    {
        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->string('stripe_payment_intent_id')->nullable()->unique()->after('stripe_checkout_session_id');
        });

        DB::statement('CREATE UNIQUE INDEX orders_stripe_payment_intent_id_unique ON orders (stripe_payment_intent_id) WHERE stripe_payment_intent_id IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX orders_stripe_payment_intent_id_unique');

        Schema::table('pending_checkouts', function (Blueprint $table) {
            $table->dropUnique(['stripe_payment_intent_id']);
            $table->dropColumn('stripe_payment_intent_id');
        });
    }
};

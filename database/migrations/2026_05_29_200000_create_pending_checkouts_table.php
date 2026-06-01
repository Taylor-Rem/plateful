<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A pre-payment snapshot of a checkout. The customer is redirected to
     * Stripe-hosted Checkout; the real `orders` row is only materialized from
     * this snapshot once payment succeeds (webhook + success return).
     */
    public function up(): void
    {
        Schema::create('pending_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('status')->default('awaiting_payment');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_checkouts');
    }
};

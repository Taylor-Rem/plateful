<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-restaurant food-refund policy (DoorDash plan Session 5 / Decision 4).
 *
 * Two independent toggles, both default OFF so a restaurant makes a deliberate
 * choice at onboarding rather than inheriting a silent policy:
 *   - pickup_refunds_enabled   — refund food when a paid PICKUP order is cancelled
 *   - delivery_refunds_enabled — refund food when a paid DELIVERY order is cancelled
 *
 * These gate only the FOOD (restaurant-revenue) portion. The delivery line is
 * refunded whenever the courier network actually gives the fee back — that is
 * the customer's money, never the restaurant's to withhold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->boolean('pickup_refunds_enabled')->default(false)->after('delivery_fee_cents');
            $table->boolean('delivery_refunds_enabled')->default(false)->after('pickup_refunds_enabled');
            // Stamped when the owner deliberately reviews the refund policy in
            // the onboarding wizard, so the step reads as complete even when they
            // (validly) leave both toggles off.
            $table->timestamp('refund_policy_reviewed_at')->nullable()->after('delivery_refunds_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn(['pickup_refunds_enabled', 'delivery_refunds_enabled', 'refund_policy_reviewed_at']);
        });
    }
};

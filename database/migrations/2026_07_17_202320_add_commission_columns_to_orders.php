<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Separates Plateful's TRUE revenue from the Stripe gross (DoorDash plan §1.2).
 *
 * `application_fee_cents` keeps its existing meaning — the amount pulled from the
 * restaurant's charge as the Stripe `application_fee_amount`. For non-delivery
 * orders that has always equalled Plateful's commission; under DoorDash Drive a
 * delivery order's Stripe fee also carries DoorDash's courier passthrough + tip
 * (Session 4b), so it is no longer a clean revenue figure.
 *
 * The three new columns carry the accounting split so the revenue ledger never
 * distributes DoorDash's money:
 *   - platform_commission_cents = the 4% (capped) commission — Plateful's real
 *     revenue, the number RevenueSplitResolver splits and the cap tracks.
 *   - delivery_margin_cents     = the 0.04×D delivery margin (0 until Session 4b).
 *   - courier_fee_cents         = D, owed to DoorDash (0 until Session 4b).
 *
 * Existing rows are backfilled: historically application_fee_cents WAS the
 * commission (delivery fees were excluded from the fee base), so copying it into
 * platform_commission_cents is exact, not an approximation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->integer('platform_commission_cents')->default(0)->after('application_fee_cents');
            $table->integer('delivery_margin_cents')->default(0)->after('platform_commission_cents');
            $table->integer('courier_fee_cents')->default(0)->after('delivery_margin_cents');
        });

        DB::table('orders')->update([
            'platform_commission_cents' => DB::raw('application_fee_cents'),
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn(['platform_commission_cents', 'delivery_margin_cents', 'courier_fee_cents']);
        });
    }
};

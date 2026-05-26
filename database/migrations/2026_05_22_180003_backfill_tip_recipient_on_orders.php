<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Best-effort backfill for orders that existed before tip_recipient was
     * tracked. Pickup orders go to the restaurant pool; delivery orders are
     * assumed to be driver tips. New orders are written by OrderPlacement
     * using TipRecipient::forOrder() and don't rely on this default.
     */
    public function up(): void
    {
        DB::table('orders')->where('type', 'pickup')->update(['tip_recipient' => 'pool']);
        DB::table('orders')->where('type', 'delivery')->update(['tip_recipient' => 'driver']);
    }

    public function down(): void
    {
        // Non-destructive backfill; nothing to revert.
    }
};

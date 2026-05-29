<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plateful's per-order application fee is a flat 1% (no subscription, no
     * tiers). The original schema defaulted to 10.00 for a since-abandoned
     * model. Lower the default and backfill any rows still on the old value.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('application_fee_percent', 5, 2)->default(1.00)->change();
        });

        DB::table('restaurants')
            ->where('application_fee_percent', 10.00)
            ->update(['application_fee_percent' => 1.00]);
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('application_fee_percent', 5, 2)->default(10.00)->change();
        });
    }
};

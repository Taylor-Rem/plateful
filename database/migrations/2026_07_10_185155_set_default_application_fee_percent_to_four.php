<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Plateful's per-order application fee moves to a flat 4% (no subscription,
     * no tiers). Raise the column default and backfill any rows still on the
     * prior 1.00 default, leaving custom super-admin overrides untouched.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('application_fee_percent', 5, 2)->default(4.00)->change();
        });

        DB::table('restaurants')
            ->where('application_fee_percent', 1.00)
            ->update(['application_fee_percent' => 4.00]);
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('application_fee_percent', 5, 2)->default(1.00)->change();
        });

        DB::table('restaurants')
            ->where('application_fee_percent', 4.00)
            ->update(['application_fee_percent' => 1.00]);
    }
};

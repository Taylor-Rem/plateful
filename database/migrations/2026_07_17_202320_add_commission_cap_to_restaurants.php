<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The monthly earnings cap, grandfathered per restaurant (DoorDash plan §1.3).
 *
 * Nullable: a null column means "use the platform default"
 * (`platform.commission_monthly_cap_cents`). Restaurant::booted() snapshots the
 * current default onto new restaurants, mirroring `application_fee_percent`, so
 * raising the platform default never retroactively changes an existing
 * restaurant's cap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->integer('commission_monthly_cap_cents')->nullable()->after('application_fee_percent');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn('commission_monthly_cap_cents');
        });
    }
};

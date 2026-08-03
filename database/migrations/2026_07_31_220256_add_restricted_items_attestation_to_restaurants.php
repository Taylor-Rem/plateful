<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the owner attested that this restaurant will not sell restricted items
 * (alcohol, tobacco, cannabis, weapons, explosives) through Plateful delivery —
 * the contractual half of DoorDash's restricted-items requirement. Null means
 * never attested; enabling delivery requires it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->timestamp('restricted_items_attested_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn('restricted_items_attested_at');
        });
    }
};

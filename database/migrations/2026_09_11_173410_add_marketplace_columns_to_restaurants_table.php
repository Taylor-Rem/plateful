<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discovery data for the Plateful app's marketplace (plateful_app_plan.md
     * Phase 1): coordinates for near-me search, cuisine tags for filtering,
     * and the listing opt-out. Listed by default — the app is a growth
     * surface every live restaurant gets for free.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('timezone');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->timestamp('geocoded_at')->nullable()->after('longitude');
            $table->json('cuisine_tags')->nullable()->after('geocoded_at');
            $table->boolean('marketplace_listed')->default(true)->after('cuisine_tags');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'geocoded_at', 'cuisine_tags', 'marketplace_listed']);
        });
    }
};

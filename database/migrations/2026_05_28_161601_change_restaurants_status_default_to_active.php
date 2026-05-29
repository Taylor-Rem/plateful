<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tightens up the lifecycle default introduced in Phase 1. Restaurants
     * created via the self-serve signup flow always go through approval and
     * have their `status` set explicitly. The only callers that hit the
     * column default are the super-admin "create restaurant" tool and ad-hoc
     * code (tests, seeders) — for those the right default is "active",
     * matching the pre-lifecycle behavior.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('status')->default('active')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('status')->default('pending_review')->change();
        });
    }
};

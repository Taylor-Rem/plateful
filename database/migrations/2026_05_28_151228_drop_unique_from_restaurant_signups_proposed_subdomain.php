<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The original column-level unique was too strict: a rejected signup
     * would permanently block that subdomain. Uniqueness against pending
     * signups (and against existing restaurants) is enforced in
     * OwnerSignupRequest at the validation layer instead.
     */
    public function up(): void
    {
        Schema::table('restaurant_signups', function (Blueprint $table) {
            $table->dropUnique('restaurant_signups_proposed_subdomain_unique');
            $table->index('proposed_subdomain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurant_signups', function (Blueprint $table) {
            $table->dropIndex(['proposed_subdomain']);
            $table->unique('proposed_subdomain');
        });
    }
};

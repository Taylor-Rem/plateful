<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Owners can request a custom domain from the onboarding wizard. We store
     * the request on the restaurant row itself (kept separate from the real
     * `custom_domain` column, which is only populated once the platform has
     * actually wired up DNS + TLS).
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('pending_custom_domain')->nullable()->after('custom_domain');
            $table->timestamp('custom_domain_requested_at')->nullable()->after('pending_custom_domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['pending_custom_domain', 'custom_domain_requested_at']);
        });
    }
};

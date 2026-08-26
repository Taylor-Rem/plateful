<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            // First-campaign review queue (campaigns plan, Session 3): null =
            // every send/schedule is held as pending_review for a super admin.
            // Stamped automatically on the first clean delivery.
            $table->timestamp('campaigns_approved_at')->nullable();

            // Platform kill switch, set by complaint auto-pause. Non-null
            // blocks all campaign sending until a super admin clears it.
            $table->timestamp('campaigns_paused_at')->nullable();
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            // Stamped by the delivered webhook; the null-check makes Svix
            // redeliveries idempotent on the campaign's delivered counter.
            $table->timestamp('delivered_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropColumn(['campaigns_approved_at', 'campaigns_paused_at']);
        });
    }
};

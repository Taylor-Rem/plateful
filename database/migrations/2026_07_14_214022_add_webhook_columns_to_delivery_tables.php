<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_integrations', function (Blueprint $table): void {
            // Per-restaurant, not platform-level: each restaurant creates the
            // webhook inside its OWN Uber dashboard (it owns the account), so
            // Uber mints a different signing key per restaurant. One shared
            // platform secret would have nothing to verify against.
            $table->text('webhook_signing_key')->nullable()->after('access_token');
        });

        Schema::table('delivery_assignments', function (Blueprint $table): void {
            // Uber retries failed webhooks at 10s/40s/100s/220s, so a stale
            // `pending` can land after `delivered`. Recording the event clock
            // lets us drop anything older than what we've already applied,
            // rather than letting a retry walk the status backwards.
            $table->timestamp('last_event_at')->nullable()->after('dropoff_eta_at');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_integrations', function (Blueprint $table): void {
            $table->dropColumn('webhook_signing_key');
        });

        Schema::table('delivery_assignments', function (Blueprint $table): void {
            $table->dropColumn('last_event_at');
        });
    }
};

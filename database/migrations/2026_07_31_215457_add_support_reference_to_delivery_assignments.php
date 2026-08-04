<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `support_reference` is the id DoorDash's support desk keys on — the number a
 * restaurant reads out when a delivery goes wrong. `provider_status` keeps the
 * provider's raw lifecycle word (e.g. `enroute_to_dropoff`) for display, since
 * the mapped DeliveryStatus flattens several of them into one case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_assignments', function (Blueprint $table): void {
            $table->string('support_reference')->nullable()->after('external_id');
            $table->string('provider_status')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_assignments', function (Blueprint $table): void {
            $table->dropColumn(['support_reference', 'provider_status']);
        });
    }
};

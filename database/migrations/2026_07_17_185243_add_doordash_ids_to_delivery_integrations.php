<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DoorDash Drive keys every restaurant off a Business + Store that Plateful
 * mints via the DoorDash API (Session 2 provisions them). Unlike the Uber
 * credential columns these are plain identifiers, not secrets, so they are NOT
 * encrypted — a rotated APP_KEY must not invalidate them. The platform JWT
 * credentials that authenticate every call live in `.env`, never here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('delivery_integrations', function (Blueprint $table): void {
            $table->string('external_business_id')->nullable()->after('customer_id');
            $table->string('external_store_id')->nullable()->after('external_business_id');
        });
    }

    public function down(): void
    {
        Schema::table('delivery_integrations', function (Blueprint $table): void {
            $table->dropColumn(['external_business_id', 'external_store_id']);
        });
    }
};

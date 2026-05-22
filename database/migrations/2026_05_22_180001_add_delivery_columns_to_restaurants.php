<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->boolean('delivery_enabled')->default(false)->after('delivery_fee_cents');
            $table->string('delivery_mode')->nullable()->after('delivery_enabled');
            $table->json('delivery_provider_priority')->nullable()->after('delivery_mode');
            $table->string('delivery_fee_strategy')->default('pass_through')->after('delivery_provider_priority');
            $table->integer('customer_delivery_fee_cents_max')->nullable()->after('delivery_fee_strategy');
            $table->string('self_delivery_tip_recipient')->default('driver')->after('customer_delivery_fee_cents_max');
            $table->string('delivery_fallback_action')->default('try_next_provider')->after('self_delivery_tip_recipient');
            $table->string('auto_cancel_refund_mode')->default('manual')->after('delivery_fallback_action');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn([
                'delivery_enabled',
                'delivery_mode',
                'delivery_provider_priority',
                'delivery_fee_strategy',
                'customer_delivery_fee_cents_max',
                'self_delivery_tip_recipient',
                'delivery_fallback_action',
                'auto_cancel_refund_mode',
            ]);
        });
    }
};

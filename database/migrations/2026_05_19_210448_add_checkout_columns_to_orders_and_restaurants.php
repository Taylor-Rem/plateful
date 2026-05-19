<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->integer('delivery_fee_cents')->default(0)->after('tax_rate_percent');
        });

        // Make user_id nullable on orders so guests can place orders.
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->string('customer_name')->nullable()->after('user_id');
            $table->string('customer_email')->nullable()->after('customer_name');
            $table->string('customer_phone', 32)->nullable()->after('customer_email');
            $table->json('delivery_address')->nullable()->after('customer_phone');
            $table->timestamp('pickup_ready_at')->nullable()->after('placed_at');
            $table->string('confirmation_token', 64)->nullable()->index()->after('pickup_ready_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'customer_name',
                'customer_email',
                'customer_phone',
                'delivery_address',
                'pickup_ready_at',
                'confirmation_token',
            ]);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable(false)->change();
        });

        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn('delivery_fee_cents');
        });
    }
};

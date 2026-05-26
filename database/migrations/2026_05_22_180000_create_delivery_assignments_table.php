<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id')->nullable();
            $table->string('status')->default('pending');
            $table->integer('quote_fee_cents')->nullable();
            $table->integer('actual_fee_cents')->nullable();
            $table->string('driver_name')->nullable();
            $table->string('driver_phone', 32)->nullable();
            $table->text('tracking_url')->nullable();
            $table->timestamp('pickup_eta_at')->nullable();
            $table->timestamp('dropoff_eta_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
    }
};

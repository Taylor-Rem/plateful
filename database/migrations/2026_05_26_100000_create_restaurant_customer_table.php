<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_customer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();

            // Denormalized counters maintained by OrderPlacement / signup flow.
            $table->timestamp('first_ordered_at')->nullable();
            $table->timestamp('last_ordered_at')->nullable();
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedBigInteger('total_spent_cents')->default(0);

            $table->timestamps();

            $table->unique(['user_id', 'restaurant_id']);
            $table->index(['restaurant_id', 'last_ordered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_customer');
    }
};

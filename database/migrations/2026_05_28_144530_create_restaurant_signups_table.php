<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `restaurant_signups` is the holding pen for self-serve restaurant
     * applications. A row is created when an owner submits the signup form;
     * the platform reviews it and either approves (creating a Restaurant and
     * linking back via restaurant_id) or rejects (with a reason).
     */
    public function up(): void
    {
        Schema::create('restaurant_signups', function (Blueprint $table) {
            $table->id();

            // The Plateful user submitting the application. Stays a customer
            // (no restaurant_user pivot) until the signup is approved.
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Populated only on approval; until then, the restaurant doesn't
            // exist yet.
            $table->foreignId('restaurant_id')
                ->nullable()
                ->constrained('restaurants')
                ->nullOnDelete();

            $table->string('proposed_name');
            $table->string('proposed_subdomain')->unique();
            $table->string('proposed_custom_domain')->nullable();
            $table->string('cuisine_type')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->text('notes')->nullable();

            // pending | approved | rejected
            $table->string('status')->default('pending')->index();

            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('rejection_reason')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('restaurant_signups');
    }
};

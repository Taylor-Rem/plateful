<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Self-serve signup no longer goes through a manual-review holding pen.
     * `OwnerSignupController@store` now creates the Restaurant directly and the
     * super-admin review surface is gone, so this table has no remaining
     * readers or writers.
     */
    public function up(): void
    {
        Schema::dropIfExists('restaurant_signups');
    }

    public function down(): void
    {
        Schema::create('restaurant_signups', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            $table->foreignId('restaurant_id')
                ->nullable()
                ->constrained('restaurants')
                ->nullOnDelete();

            $table->string('proposed_name');
            $table->string('proposed_subdomain')->index();
            $table->string('proposed_custom_domain')->nullable();
            $table->string('cuisine_type')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 2)->nullable();
            $table->text('notes')->nullable();

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
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Plateful charges per restaurant, not per user — one owner can run
     * multiple restaurants on Plateful and each gets its own subscription.
     * This moves the Cashier "customer" from users to restaurants:
     *
     *   - Adds stripe_id / pm_* / trial_ends_at columns to restaurants
     *   - Renames subscriptions.user_id → restaurant_id (no rows exist yet
     *     because Cashier was never wired up, so this is a pure schema swap)
     *   - Drops the Cashier columns from users
     *
     * Cashier picks up the new owner via Cashier::useCustomerModel(Restaurant::class)
     * in AppServiceProvider — Cashier infers the FK from the model's
     * getForeignKey(), which is "restaurant_id".
     */
    public function up(): void
    {
        if (DB::table('subscriptions')->count() > 0) {
            // Safety net — should never trigger because Cashier was never
            // wired up to users, but if someone has stale rows we want a
            // loud failure rather than orphaned data.
            throw new RuntimeException('Refusing to move billing columns while subscriptions has rows that reference users — manually migrate first.');
        }

        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index()->after('stripe_account_status');
            $table->string('pm_type')->nullable()->after('stripe_id');
            $table->string('pm_last_four', 4)->nullable()->after('pm_type');
            $table->timestamp('trial_ends_at')->nullable()->after('pm_last_four');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_user_id_stripe_status_index');
            $table->renameColumn('user_id', 'restaurant_id');
        });

        // Index creation runs after the rename finalizes (SQLite quirk).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['restaurant_id', 'stripe_status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['restaurant_id', 'stripe_status']);
            $table->renameColumn('restaurant_id', 'user_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['user_id', 'stripe_status']);
        });

        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }
};

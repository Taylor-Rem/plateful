<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The earnings ledger. One immutable row per (order, earner, role) written
     * when an order is paid, snapshotting how that order's retained platform
     * fee was attributed. Snapshots (role, percent, amount, earned_at) so later
     * changes to assignments or share config never rewrite history — the
     * monthly earnings report reads straight from here.
     */
    public function up(): void
    {
        Schema::create('fee_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained('restaurants')->cascadeOnDelete();
            // The earner. Nulled (not cascaded) if the user is removed so the
            // payout history survives; the report buckets orphaned rows.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role');
            $table->decimal('percent', 5, 2);
            $table->integer('amount_cents');
            // Snapshot of the order's placed_at, so monthly grouping needs no
            // join back to orders.
            $table->timestamp('earned_at');
            $table->timestamps();

            // Idempotency: a replayed webhook/return must not double-write a
            // slice. One role earns at most once per order per user.
            $table->unique(['order_id', 'user_id', 'role']);
            $table->index(['user_id', 'earned_at']);
            $table->index(['restaurant_id', 'earned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_distributions');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamp('occurred_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'occurred_at']);
        });

        // Backfill an initial event row for every existing order so the
        // timeline is never empty for orders placed before this migration.
        $now = now();
        DB::table('orders')->orderBy('id')->each(function ($order) use ($now): void {
            DB::table('order_events')->insert([
                'order_id' => $order->id,
                'from_status' => null,
                'to_status' => $order->status,
                'occurred_at' => $order->placed_at ?? $order->created_at ?? $now,
                'user_id' => null,
                'note' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
    }
};

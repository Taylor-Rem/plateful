<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-line special instructions ("no onions") carried over from
     * cart_items.notes at checkout so the kitchen sees them on the ticket.
     */
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->text('notes')->nullable()->after('modifiers');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('notes');
        });
    }
};

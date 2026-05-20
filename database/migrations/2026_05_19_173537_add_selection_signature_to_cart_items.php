<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->string('selection_signature', 64)->nullable()->after('modifiers');
            $table->index(['cart_id', 'menu_item_id', 'selection_signature'], 'cart_items_grouping_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table): void {
            $table->dropIndex('cart_items_grouping_idx');
            $table->dropColumn('selection_signature');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An item used to hold one template (`menu_items.item_template_id`). It now
 * holds many, ordered, through this pivot — "Sandwich size" stays shared
 * while the item adds its own ingredient groups and swap sets beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_template_id')->constrained()->cascadeOnDelete();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->unique(['menu_item_id', 'item_template_id']);
            $table->index('item_template_id');
        });

        // Swap sets are attached *through* an ingredient ("mortadella — swap
        // with Meats"). Remembering which one lets the compiler detach the set
        // when the ingredient stops swapping, without touching templates the
        // owner attached by hand.
        Schema::table('menu_item_templates', function (Blueprint $table) {
            $table->foreignId('menu_item_ingredient_id')
                ->nullable()
                ->after('item_template_id')
                ->constrained()
                ->nullOnDelete();
        });

        $now = now();
        DB::table('menu_items')
            ->whereNotNull('item_template_id')
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($now): void {
                $rows = [];
                foreach ($items as $item) {
                    $rows[] = [
                        'menu_item_id' => $item->id,
                        'item_template_id' => $item->item_template_id,
                        'position' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('menu_item_templates')->insert($rows);
            });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['item_template_id']);
            $table->dropIndex(['item_template_id']);
            $table->dropColumn('item_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->foreignId('item_template_id')
                ->nullable()
                ->after('menu_category_id')
                ->constrained('item_templates')
                ->nullOnDelete();
            $table->index('item_template_id');
        });

        DB::table('menu_item_templates')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('menu_item_id')
            ->each(function ($rows, $itemId): void {
                DB::table('menu_items')
                    ->where('id', $itemId)
                    ->update(['item_template_id' => $rows->first()->item_template_id]);
            });

        Schema::dropIfExists('menu_item_templates');
    }
};

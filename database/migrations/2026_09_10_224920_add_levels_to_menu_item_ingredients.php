<?php

use App\Models\MenuItem;
use App\Support\Menus\IngredientGroupCompiler;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingredient customization moves from one "Leave anything out?" checklist
 * plus an "Extras" group to one pick-one row per ingredient — None / Half /
 * Regular / Double. `allow_half` is the new per-ingredient rule; groups
 * remember which ingredient they were compiled from so re-saves upsert.
 * Every item with ingredients is recompiled at the end so existing menus
 * switch shape in the same deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_item_ingredients', function (Blueprint $table) {
            $table->boolean('allow_half')->default(true)->after('is_removable');
        });

        Schema::table('item_template_groups', function (Blueprint $table) {
            $table->foreignId('menu_item_ingredient_id')
                ->nullable()
                ->after('menu_item_id')
                ->constrained()
                ->cascadeOnDelete();
        });

        $compiler = app(IngredientGroupCompiler::class);

        MenuItem::withoutTenantScope()
            ->whereHas('ingredients')
            ->orderBy('id')
            ->chunkById(100, function ($items) use ($compiler): void {
                foreach ($items as $item) {
                    $compiler->compile($item);
                }
            });
    }

    public function down(): void
    {
        Schema::table('item_template_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_item_ingredient_id');
        });

        Schema::table('menu_item_ingredients', function (Blueprint $table) {
            $table->dropColumn('allow_half');
        });
    }
};

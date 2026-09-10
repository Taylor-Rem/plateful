<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ingredients are per item: "mortadella — can leave out, extra $1.50, swap
 * with Meats". They are the owner-facing source of truth; a compiler turns
 * them into item-owned option groups so the cart, pricing, order integrity
 * check, and POS notes keep speaking "option ids in groups".
 *
 * Groups therefore belong to either a reusable template or a single item,
 * and carry a `kind`. Generated options remember the ingredient they came
 * from so re-saving upserts them instead of recreating (stable ids keep
 * existing cart lines valid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_item_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->integer('position')->default(0);
            $table->boolean('is_removable')->default(true);
            $table->integer('extra_price_cents')->nullable();
            $table->foreignId('swap_template_id')
                ->nullable()
                ->constrained('item_templates')
                ->nullOnDelete();
            $table->timestamps();

            $table->index('menu_item_id');
        });

        Schema::table('item_template_groups', function (Blueprint $table) {
            $table->foreignId('item_template_id')->nullable()->change();
            $table->foreignId('menu_item_id')
                ->nullable()
                ->after('item_template_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('kind', 20)->default('choice')->after('name');

            $table->index('menu_item_id');
        });

        Schema::table('item_template_options', function (Blueprint $table) {
            $table->foreignId('menu_item_ingredient_id')
                ->nullable()
                ->after('item_template_group_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('kind', 20)->default('choice')->after('name');

            $table->index('menu_item_ingredient_id');
        });
    }

    public function down(): void
    {
        Schema::table('item_template_options', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_item_ingredient_id');
            $table->dropColumn('kind');
        });

        Schema::table('item_template_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('menu_item_id');
            $table->dropColumn('kind');
        });

        Schema::dropIfExists('menu_item_ingredients');
    }
};

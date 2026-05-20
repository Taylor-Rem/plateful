<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index('restaurant_id');
        });

        Schema::create('item_template_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('min_selections')->default(0);
            $table->integer('max_selections')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index('item_template_id');
        });

        Schema::create('item_template_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_template_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('price_delta_cents')->default(0);
            $table->boolean('is_available')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index('item_template_group_id');
        });

        Schema::table('menu_items', function (Blueprint $table) {
            $table->foreignId('item_template_id')
                ->nullable()
                ->after('menu_category_id')
                ->constrained('item_templates')
                ->nullOnDelete();

            $table->index('item_template_id');
        });

        Schema::create('menu_item_default_selections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_template_option_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['menu_item_id', 'item_template_option_id'], 'mids_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_item_default_selections');

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['item_template_id']);
            $table->dropColumn('item_template_id');
        });

        Schema::dropIfExists('item_template_options');
        Schema::dropIfExists('item_template_groups');
        Schema::dropIfExists('item_templates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->boolean('is_featured')->default(false)->after('is_available');
            $table->index(['restaurant_id', 'is_featured']);
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table): void {
            $table->dropIndex(['restaurant_id', 'is_featured']);
            $table->dropColumn('is_featured');
        });
    }
};

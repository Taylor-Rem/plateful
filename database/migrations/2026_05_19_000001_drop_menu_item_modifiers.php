<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('menu_item_modifiers');
    }

    public function down(): void
    {
        // No-op: the original creation migration owned this table.
    }
};

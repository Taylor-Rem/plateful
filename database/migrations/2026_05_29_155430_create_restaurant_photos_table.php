<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('image_path')->nullable();
            $table->string('caption', 140)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['restaurant_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_photos');
    }
};

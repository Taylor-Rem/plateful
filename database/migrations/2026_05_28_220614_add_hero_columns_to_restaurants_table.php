<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->string('hero_image_path')->nullable()->after('logo_path');
            $table->string('hero_tagline')->nullable()->after('hero_image_path');
            $table->string('hero_cta_label', 64)->nullable()->after('hero_tagline');
            $table->string('hero_cta_url')->nullable()->after('hero_cta_label');
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table): void {
            $table->dropColumn(['hero_image_path', 'hero_tagline', 'hero_cta_label', 'hero_cta_url']);
        });
    }
};

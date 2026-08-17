<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stories live as markdown files in git; this table only overrides the
     * front matter's `published` flag so a super admin can flip a story live
     * (or pull it) in production without waiting for a deploy.
     */
    public function up(): void
    {
        Schema::create('story_publish_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->boolean('published');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('story_publish_overrides');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The platform-wide revenue-role singletons: exactly one user holds each
     * of `founder` and `operator` at a time. Keeping these in a table (rather
     * than config) makes the Founder→successor and Operator handoff a data
     * change with no deploy, and keeps the assignment auditable.
     */
    public function up(): void
    {
        Schema::create('platform_role_holders', function (Blueprint $table) {
            $table->id();
            $table->string('role')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_role_holders');
    }
};

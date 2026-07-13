<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-restaurant revenue-role assignments. The recruiter (who signed the
     * restaurant) and the overseer (top-level support) each earn a share of
     * Plateful's retained fee. Both are nullable: a null overseer falls back to
     * the platform Operator (see PlatformRoleHolder). On user deletion the
     * assignment clears rather than cascading the restaurant away.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->foreignId('recruiter_id')->nullable()->after('application_fee_percent')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('overseer_id')->nullable()->after('recruiter_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recruiter_id');
            $table->dropConstrainedForeignId('overseer_id');
        });
    }
};

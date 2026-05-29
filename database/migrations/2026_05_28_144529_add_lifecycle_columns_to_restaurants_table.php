<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds lifecycle columns to the restaurants table to support self-serve
     * signup. Existing rows are backfilled to `status = active` so nothing
     * already in the database is unintentionally hidden.
     */
    public function up(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->string('status')
                ->default('pending_review')
                ->after('is_active')
                ->index();

            $table->timestamp('approved_at')->nullable()->after('status');
            $table->foreignId('approved_by_user_id')
                ->nullable()
                ->after('approved_at')
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('suspended_at')->nullable()->after('approved_by_user_id');
            $table->string('suspension_reason')->nullable()->after('suspended_at');

            $table->timestamp('onboarding_completed_at')->nullable()->after('suspension_reason');
        });

        // Existing restaurants predate the lifecycle workflow; treat them as
        // fully active so they continue to behave exactly as before.
        DB::table('restaurants')->update(['status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('restaurants', function (Blueprint $table) {
            $table->dropForeign(['approved_by_user_id']);
            $table->dropColumn([
                'status',
                'approved_at',
                'approved_by_user_id',
                'suspended_at',
                'suspension_reason',
                'onboarding_completed_at',
            ]);
        });
    }
};

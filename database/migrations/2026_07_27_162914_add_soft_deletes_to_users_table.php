<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add soft deletes to users and make the uniqueness constraints
     * soft-delete-aware. Plain uniques on `email` / `google_id` keep a deleted
     * account's identifiers reserved forever; partial uniques let a freed
     * address (or linked Google account) be reused while still blocking a
     * second *live* account on the same identifier.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->dropUnique('users_google_id_unique');
        });

        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX users_google_id_unique ON users (google_id) WHERE deleted_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX users_email_unique');
        DB::statement('DROP INDEX users_google_id_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->unique('email', 'users_email_unique');
            $table->unique('google_id', 'users_google_id_unique');
            $table->dropSoftDeletes();
        });
    }
};

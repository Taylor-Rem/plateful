<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the postgres partial-unique on email-where-restaurant-id-is-null
        // (created in the initial users migration for platform admins).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_email_platform_unique');
        }

        Schema::table('users', function (Blueprint $table) {
            // Drop the composite (restaurant_id, email) unique that scoped customer emails per restaurant.
            $table->dropUnique(['restaurant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['restaurant_id', 'email']);
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX users_email_platform_unique ON users (email) WHERE restaurant_id IS NULL');
        }
    }
};

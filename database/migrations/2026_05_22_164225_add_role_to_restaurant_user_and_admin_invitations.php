<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_user', function (Blueprint $table): void {
            $table->string('role')->default('admin')->after('restaurant_id');
        });

        Schema::table('admin_invitations', function (Blueprint $table): void {
            $table->string('role')->default('admin')->after('restaurant_id');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_user', function (Blueprint $table): void {
            $table->dropColumn('role');
        });

        Schema::table('admin_invitations', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the platform Founder and Operator to the earliest super admin so the
     * revenue split resolves from day one. Skipped if there's no super admin
     * (e.g. a fresh test database) or a holder is already set. Holders are
     * reassignable in the console afterwards.
     */
    public function up(): void
    {
        $superAdminId = DB::table('users')
            ->where('is_super_admin', true)
            ->orderBy('id')
            ->value('id');

        if (! $superAdminId) {
            return;
        }

        foreach (['founder', 'operator'] as $role) {
            $exists = DB::table('platform_role_holders')->where('role', $role)->exists();
            if ($exists) {
                continue;
            }

            DB::table('platform_role_holders')->insert([
                'role' => $role,
                'user_id' => $superAdminId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('platform_role_holders')->whereIn('role', ['founder', 'operator'])->delete();
    }
};

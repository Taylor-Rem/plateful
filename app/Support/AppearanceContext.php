<?php

namespace App\Support;

class AppearanceContext
{
    public const APEX = 'apex';

    public const ADMIN = 'admin';

    public const TENANT = 'tenant';

    /**
     * Resolve the appearance context for a host.
     *
     * - apex   = the platform primary domain itself
     * - admin  = the admin subdomain on the primary domain
     * - tenant = any other subdomain of the primary domain (or any other host)
     */
    public static function forHost(string $host): string
    {
        $primary = (string) config('platform.primary_domain');
        $adminHost = ((string) config('platform.admin_subdomain')).'.'.$primary;

        if ($host === $primary) {
            return self::APEX;
        }

        if ($host === $adminHost) {
            return self::ADMIN;
        }

        return self::TENANT;
    }

    public static function isTenant(string $host): bool
    {
        return self::forHost($host) === self::TENANT;
    }
}

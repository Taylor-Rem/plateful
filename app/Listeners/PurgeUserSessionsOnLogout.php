<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\DB;

/**
 * Sessions are host-scoped (SESSION_DOMAIN is null), so a user can hold
 * separate sessions on the admin host, the primary host, and storefronts.
 * Logging out only destroys the current host's session, which reads as
 * "I signed out but I'm still signed in over there". Since sessions are
 * database-backed, treat logout as global: delete every session row for the
 * user. Remember-me cookies are already invalidated globally by Laravel
 * cycling the remember token on logout.
 */
class PurgeUserSessionsOnLogout
{
    public function handle(Logout $event): void
    {
        if (! $event->user || config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $event->user->getAuthIdentifier())
            ->delete();
    }
}

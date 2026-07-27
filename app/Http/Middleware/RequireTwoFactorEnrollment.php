<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Fortify\Features;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force super admins to enroll in two-factor authentication before using the
 * admin console. Applied to every authenticated admin-host route except the
 * account security page itself (where enrollment happens), so the redirect
 * can never loop. Restaurant admins and staff are nudged, not forced — see
 * TwoFactorNudgeBanner on the frontend.
 */
class RequireTwoFactorEnrollment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && $user->isSuperAdmin()
            && Features::canManageTwoFactorAuthentication()
            && ! $user->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.edit');
        }

        return $next($request);
    }
}

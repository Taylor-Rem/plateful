<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Support\StorefrontLoginHandoff;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Storefront-side landing for the owner-preview handoff. The admin console
 * mints a single-use token (see OnboardingController::preview); this exchanges
 * it for a session on the storefront host so ResolveTenant recognizes the
 * owner and lets them browse their not-yet-live site.
 */
class PreviewController extends Controller
{
    public function enter(Request $request, StorefrontLoginHandoff $handoff, CurrentTenant $tenant): RedirectResponse
    {
        $user = $handoff->consume((string) $request->query('token', ''), $request->getHost());

        if ($user === null) {
            // Expired or replayed token. Send them back to the wizard, which
            // can mint a fresh one — the admin host is where they came from.
            $adminUrl = $request->getScheme().'://'
                .config('platform.admin_subdomain').'.'.config('platform.primary_domain')
                .'/'.$tenant->get()->subdomain.'/onboarding';

            return redirect()->away($adminUrl);
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }
}

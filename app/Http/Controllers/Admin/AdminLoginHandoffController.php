<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\StorefrontLoginHandoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Exchanges a single-use, host-bound handoff token for a session on the admin
 * host. Sessions are host-scoped, so a login established on the primary host
 * (e.g. right after owner signup) cannot cross to admin.* by cookie — the
 * token carries it instead. See StorefrontLoginHandoff.
 */
class AdminLoginHandoffController extends Controller
{
    public function __invoke(Request $request, StorefrontLoginHandoff $handoff): RedirectResponse
    {
        $user = $handoff->consume((string) $request->query('token', ''), $request->getHost());

        if ($user === null) {
            return redirect('/')->with('error', 'That sign-in link has expired. Please log in again.');
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect($this->safeInternalPath((string) $request->query('to', '/')));
    }

    /**
     * Only ever redirect within this host: a relative path that doesn't start
     * with `//` (protocol-relative URLs would escape to another origin).
     */
    private function safeInternalPath(string $to): string
    {
        if (! str_starts_with($to, '/') || str_starts_with($to, '//')) {
            return '/';
        }

        return $to;
    }
}

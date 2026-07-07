<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use App\Support\StorefrontLoginHandoff;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Throwable;

/**
 * "Sign in with Google" for customers.
 *
 * Google allows a single redirect URI and no wildcard subdomains, so the OAuth
 * dance always runs on the platform host (redirect + callback). The storefront
 * a customer started from is captured on redirect and they are returned there
 * after login — see StorefrontLoginHandoff for how the authenticated session
 * crosses to the storefront subdomain.
 */
class GoogleController extends Controller
{
    private const RETURN_HOST_SESSION_KEY = 'auth.google.return_host';

    public function __construct(private readonly StorefrontLoginHandoff $handoff) {}

    /**
     * Send the customer to Google, remembering the storefront to return to.
     *
     * Both this action and the callback run on the platform host, so the
     * captured host survives the round-trip in the platform-host session
     * (which also carries Socialite's CSRF state).
     */
    public function redirect(Request $request): SymfonyRedirectResponse
    {
        if (! $this->googleConfigured()) {
            return $this->failureRedirect($request, $this->returnHostFromRequest($request));
        }

        $returnHost = $this->returnHostFromRequest($request);

        if ($returnHost !== null) {
            $request->session()->put(self::RETURN_HOST_SESSION_KEY, $returnHost);
        } else {
            $request->session()->forget(self::RETURN_HOST_SESSION_KEY);
        }

        return Socialite::driver('google')->redirect();
    }

    /**
     * Handle Google's callback: resolve or create the account, then return the
     * customer to the storefront they came from.
     */
    public function callback(Request $request): SymfonyRedirectResponse
    {
        $returnHost = $request->session()->pull(self::RETURN_HOST_SESSION_KEY);

        if ($request->has('error') || ! $this->googleConfigured()) {
            return $this->failureRedirect($request, $returnHost);
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (Throwable) {
            return $this->failureRedirect($request, $returnHost);
        }

        $user = $this->resolveUser($googleUser);

        if ($user === null) {
            // The email belongs to an existing account but Google has not
            // verified it — refuse to link or log in, to block takeover of an
            // account via an unverified Google email.
            return $this->failureRedirect($request, $returnHost);
        }

        $primary = config('platform.primary_domain');

        if ($returnHost === null || $returnHost === $primary) {
            Auth::login($user, remember: true);

            return redirect()->intended(route('home'));
        }

        $token = $this->handoff->issue($user, $returnHost);

        return redirect()->away(
            $request->getScheme().'://'.$returnHost.'/auth/google/finish?'.http_build_query(['token' => $token])
        );
    }

    /**
     * Storefront-side token exchange. Runs on the tenant host, verifies the
     * one-time handoff token, and establishes the session there.
     */
    public function finish(Request $request, CurrentTenant $tenant): RedirectResponse
    {
        $user = $this->handoff->consume((string) $request->query('token', ''), $request->getHost());

        if ($user === null) {
            return redirect()->route('login')
                ->with('error', 'Your Google sign-in link has expired. Please try again.');
        }

        Auth::login($user, remember: true);

        // Mirror Decision F: associate the customer with this storefront on
        // first sign-in, even before their first order.
        if ($tenant->check()) {
            RestaurantCustomer::firstOrCreate([
                'user_id' => $user->id,
                'restaurant_id' => $tenant->id(),
            ]);
        }

        return redirect()->intended(route('storefront.account.show'));
    }

    /**
     * Match, or create, the local user for a Google account.
     *
     * Order: (1) an existing google_id, then (2) an existing email — but only
     * auto-link by email when Google reports it verified. Returns null when the
     * email is taken but unverified (the caller treats this as a failure).
     */
    private function resolveUser(SocialiteUser $googleUser): ?User
    {
        $existingByGoogleId = User::query()->where('google_id', $googleUser->getId())->first();

        if ($existingByGoogleId !== null) {
            return $existingByGoogleId;
        }

        $emailVerified = ($googleUser->user['email_verified'] ?? false) === true;
        $existingByEmail = User::query()->where('email', $googleUser->getEmail())->first();

        if ($existingByEmail !== null) {
            if (! $emailVerified) {
                return null;
            }

            $existingByEmail->forceFill([
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
            ])->save();

            return $existingByEmail;
        }

        return tap(new User, function (User $user) use ($googleUser, $emailVerified): void {
            $user->forceFill([
                'name' => $googleUser->getName() ?: $googleUser->getEmail(),
                'email' => $googleUser->getEmail(),
                'google_id' => $googleUser->getId(),
                'avatar' => $googleUser->getAvatar(),
                'password' => Str::password(32),
                'email_verified_at' => $emailVerified ? now() : null,
            ])->save();
        });
    }

    private function googleConfigured(): bool
    {
        return filled(config('services.google.client_id'));
    }

    /**
     * Validate the return_to query and return its host, or null.
     */
    private function returnHostFromRequest(Request $request): ?string
    {
        $returnTo = $request->query('return_to');

        if (! is_string($returnTo) || $returnTo === '') {
            return null;
        }

        $host = parse_url($returnTo, PHP_URL_HOST);

        if (! is_string($host) || ! $this->isAllowedReturnHost($host)) {
            return null;
        }

        return $host;
    }

    /**
     * A return host is allowed only if it is the platform host or one of its
     * storefront subdomains / custom domains. This blocks open redirects to
     * arbitrary hosts through the OAuth flow.
     */
    private function isAllowedReturnHost(string $host): bool
    {
        $primary = (string) config('platform.primary_domain');
        $adminHost = config('platform.admin_subdomain').'.'.$primary;

        if ($host === $primary) {
            return true;
        }

        if ($host === $adminHost) {
            return false;
        }

        if (str_ends_with($host, '.'.$primary)) {
            return true;
        }

        return Restaurant::query()->where('custom_domain', $host)->exists();
    }

    /**
     * Send the customer back to a login page after a denied or failed attempt,
     * on the storefront they came from when we know it.
     */
    private function failureRedirect(Request $request, ?string $returnHost): SymfonyRedirectResponse
    {
        $primary = config('platform.primary_domain');

        if ($returnHost !== null && $returnHost !== $primary) {
            return redirect()->away(
                $request->getScheme().'://'.$returnHost.'/login?'.http_build_query(['google' => 'failed'])
            );
        }

        return redirect()->route('login', ['google' => 'failed']);
    }
}

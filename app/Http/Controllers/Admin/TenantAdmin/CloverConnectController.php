<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Exceptions\PosProviderException;
use App\Http\Controllers\Controller;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use App\Services\Pos\Clover\CloverOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the Clover "Connect your POS" OAuth handshake. `connect` and
 * `disconnect` are restaurant-scoped; `callback` is not — Clover posts back to a
 * single registered redirect URI, so the restaurant travels in the `state` we
 * stash in the session and verify on return. Unlike Square, Clover returns the
 * merchant id (its order-scoping "location") directly in the callback query, so
 * there is no separate location lookup.
 */
class CloverConnectController extends Controller
{
    private const SESSION_KEY = 'pos.clover.oauth';

    private const STATE_TTL_MINUTES = 15;

    public function __construct(private CloverOAuthService $oauth) {}

    /**
     * Mint a single-use state, remember which restaurant it belongs to, and send
     * the owner to Clover to authorize.
     */
    public function connect(Request $request, Restaurant $restaurant): Response
    {
        $this->authorize('managePos', $restaurant);

        $state = Str::random(40);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'restaurant_id' => $restaurant->id,
            'expires_at' => now()->addMinutes(self::STATE_TTL_MINUTES)->timestamp,
        ]);

        return Inertia::location($this->oauth->buildAuthorizeUrl($state));
    }

    /**
     * Clover redirects the browser back here after the owner approves (or
     * denies). Verify state, exchange the code for tokens, and persist the
     * connection with the merchant id Clover included in the query.
     */
    public function callback(Request $request): RedirectResponse
    {
        $stored = $request->session()->pull(self::SESSION_KEY);

        $restaurant = $this->resolveStatefulRestaurant($request, $stored);

        if ($restaurant === null) {
            return redirect()->route('admin.home')
                ->with('error', 'That Clover connection link expired or was invalid. Please try again.');
        }

        $this->authorize('managePos', $restaurant);

        $settings = fn (): RedirectResponse => redirect()->route('admin.restaurant.pos.show', [
            'restaurant' => $restaurant->subdomain,
        ]);

        if ($request->filled('error')) {
            return $settings()->with('error', 'Clover connection was cancelled.');
        }

        $merchantId = (string) $request->query('merchant_id', '');

        if (! $request->filled('code') || $merchantId === '') {
            return $settings()->with('error', 'Clover did not return the expected authorization details. Please try again.');
        }

        try {
            $tokens = $this->oauth->exchangeCode((string) $request->query('code'));
        } catch (PosProviderException $e) {
            Log::error('Clover OAuth callback failed', [
                'restaurant_id' => $restaurant->id,
                'error' => $e->getMessage(),
            ]);

            return $settings()->with('error', 'We could not finish connecting Clover. Please try again.');
        }

        PosIntegration::withoutTenantScope()->updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'provider' => PosProviderName::Clover->value],
            [
                'external_merchant_id' => $merchantId,
                // Clover scopes orders by merchant id; mirror it into location_id
                // so the "connected" checks and admin display stay uniform.
                'location_id' => $merchantId,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'token_expires_at' => $tokens->expiresAt,
                'status' => PosIntegrationStatus::Connected,
                'scopes' => $this->oauth->requestedScopes(),
                'last_error' => null,
            ],
        );

        return $settings()->with('success', 'Clover is connected — new orders will print to your register.');
    }

    /**
     * Drop our stored credentials. Clover has no lightweight token-revoke
     * endpoint (a merchant fully removes access by uninstalling the app), so
     * disconnect is a local delete — the merchant can also uninstall on Clover.
     */
    public function disconnect(Restaurant $restaurant): RedirectResponse
    {
        $this->authorize('managePos', $restaurant);

        PosIntegration::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('provider', PosProviderName::Clover->value)
            ->first()?->delete();

        return redirect()->route('admin.restaurant.pos.show', ['restaurant' => $restaurant->subdomain])
            ->with('success', 'Clover has been disconnected.');
    }

    /**
     * Validate the stashed OAuth state against the callback and return the
     * restaurant it was minted for, or null if anything looks off.
     *
     * @param  array{state?: string, restaurant_id?: int, expires_at?: int}|null  $stored
     */
    private function resolveStatefulRestaurant(Request $request, ?array $stored): ?Restaurant
    {
        if ($stored === null) {
            return null;
        }

        $matches = hash_equals((string) ($stored['state'] ?? ''), (string) $request->query('state', ''));
        $fresh = ($stored['expires_at'] ?? 0) >= now()->timestamp;

        if (! $matches || ! $fresh) {
            return null;
        }

        return Restaurant::find($stored['restaurant_id'] ?? null);
    }
}

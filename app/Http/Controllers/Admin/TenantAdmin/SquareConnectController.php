<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Exceptions\PosProviderException;
use App\Http\Controllers\Controller;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use App\Services\Pos\Square\SquareOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the Square "Connect your POS" OAuth handshake. `connect` and
 * `disconnect` are restaurant-scoped; `callback` is not — Square posts back to
 * a single registered redirect URI, so the restaurant travels in the `state`
 * we stash in the session and verify on return.
 */
class SquareConnectController extends Controller
{
    private const SESSION_KEY = 'pos.square.oauth';

    private const STATE_TTL_MINUTES = 15;

    public function __construct(private SquareOAuthService $oauth) {}

    /**
     * Mint a single-use state, remember which restaurant it belongs to, and
     * send the owner to Square to authorize.
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
     * Square redirects the browser back here after the owner approves (or
     * denies). Verify state, exchange the code for tokens, resolve the primary
     * location, and persist the connection.
     */
    public function callback(Request $request): RedirectResponse
    {
        $stored = $request->session()->pull(self::SESSION_KEY);

        $restaurant = $this->resolveStatefulRestaurant($request, $stored);

        if ($restaurant === null) {
            return redirect()->route('admin.home')
                ->with('error', 'That Square connection link expired or was invalid. Please try again.');
        }

        $this->authorize('managePos', $restaurant);

        $settings = fn (): RedirectResponse => redirect()->route('admin.restaurant.pos.show', [
            'restaurant' => $restaurant->subdomain,
        ]);

        if ($request->filled('error')) {
            return $settings()->with('error', 'Square connection was cancelled.');
        }

        if (! $request->filled('code')) {
            return $settings()->with('error', 'Square did not return an authorization code. Please try again.');
        }

        try {
            $tokens = $this->oauth->exchangeCode((string) $request->query('code'));
            $locationId = $this->oauth->fetchPrimaryLocationId($tokens->accessToken);
        } catch (PosProviderException $e) {
            Log::error('Square OAuth callback failed', [
                'restaurant_id' => $restaurant->id,
                'error' => $e->getMessage(),
            ]);

            return $settings()->with('error', 'We could not finish connecting Square. Please try again.');
        }

        PosIntegration::withoutTenantScope()->updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'provider' => PosProviderName::Square->value],
            [
                'external_merchant_id' => $tokens->merchantId,
                'location_id' => $locationId,
                'access_token' => $tokens->accessToken,
                'refresh_token' => $tokens->refreshToken,
                'token_expires_at' => $tokens->expiresAt,
                'status' => PosIntegrationStatus::Connected,
                'scopes' => $this->oauth->requestedScopes(),
                'last_error' => null,
            ],
        );

        return $settings()->with('success', 'Square is connected — new orders will print to your register.');
    }

    /**
     * Revoke on Square's side (best-effort) and drop our stored credentials.
     */
    public function disconnect(Restaurant $restaurant): RedirectResponse
    {
        $this->authorize('managePos', $restaurant);

        $integration = PosIntegration::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('provider', PosProviderName::Square->value)
            ->first();

        if ($integration !== null) {
            if ($integration->access_token !== null) {
                $this->oauth->revoke($integration->access_token);
            }

            $integration->delete();
        }

        return redirect()->route('admin.restaurant.pos.show', ['restaurant' => $restaurant->subdomain])
            ->with('success', 'Square has been disconnected.');
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

<?php

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryIntegration;
use App\Services\Delivery\DeliveryQuoteRequest;
use App\Services\Delivery\UberDirect\UberDirectProvider;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

/**
 * Opt-in LIVE integration test — makes real calls to Uber's auth endpoint and
 * is skipped unless sandbox credentials are present:
 *
 *   UBER_DIRECT_SANDBOX_CLIENT_ID=...
 *   UBER_DIRECT_SANDBOX_CLIENT_SECRET=...
 *   UBER_DIRECT_SANDBOX_CUSTOMER_ID=...
 *
 * Get them from direct.uber.com -> Management -> Developer. A test sandbox is
 * provisioned automatically; these credentials create no real deliveries, and
 * minting a token has no side effect beyond Uber's 100-per-hour grant cap.
 *
 * Run just this file:
 *   php artisan test tests/Feature/Delivery/UberDirectLiveSandboxTest.php
 *
 * It is NOT part of the normal suite (no creds -> skipped), so CI stays
 * deterministic and offline.
 *
 * NOTE: the credential lookup and the skip condition are both deferred into the
 * test lifecycle on purpose. `.env` is loaded when the application boots, which
 * happens in setUp() — long after Pest has collected this file. Reading env at
 * the top level of the file (as CloverLiveSandboxTest does) always yields null,
 * so such a test skips even when the credentials are set.
 *
 * ---------------------------------------------------------------------------
 * INVARIANT: NOTHING IN THIS FILE MAY CREATE A DELIVERY.
 * ---------------------------------------------------------------------------
 * Every call here must be side-effect-free — minting a token and fetching a
 * quote create nothing and cost nothing, which is what makes them safe to run
 * even if production credentials end up in `.env` by mistake. That is the only
 * thing protecting this file from dispatching a real courier to a real address.
 *
 * It cannot be enforced programmatically before the fact: Uber exposes
 * `live_mode` on the *delivery* object and on webhook payloads, but neither the
 * token response nor the quote response says which environment you are in. The
 * dashboard toggle decides, and the credentials carry it silently.
 *
 * So if you ever add a test that calls `create()`, it MUST assert
 * `live_mode === false` on the response and fail loudly otherwise — and even
 * then it is creating something, so think hard about whether a faked test would
 * do. `UberDirectProviderTest` covers create/cancel against Http::fake for
 * exactly this reason.
 */

/**
 * @return array{clientId: string, clientSecret: string, customerId: string}|null
 */
function uberSandboxCredentials(): ?array
{
    $clientId = (string) config('services.uber_direct.sandbox_client_id');
    $clientSecret = (string) config('services.uber_direct.sandbox_client_secret');
    $customerId = (string) config('services.uber_direct.sandbox_customer_id');

    if ($clientId === '' || $clientSecret === '' || $customerId === '') {
        return null;
    }

    return [
        'clientId' => $clientId,
        'clientSecret' => $clientSecret,
        'customerId' => $customerId,
    ];
}

function uberSandboxMissing(): bool
{
    return uberSandboxCredentials() === null;
}

const UBER_SKIP_REASON = 'Set UBER_DIRECT_SANDBOX_CLIENT_ID, _CLIENT_SECRET and _CUSTOMER_ID to run the live Uber Direct sandbox test.';

it('mints a real access token from the Uber sandbox', function () {
    ['clientId' => $clientId, 'clientSecret' => $clientSecret] = uberSandboxCredentials();

    $token = app(UberDirectTokenService::class)->requestToken($clientId, $clientSecret);

    expect($token->accessToken)->toBeString()->not->toBeEmpty();

    // Uber documents a 30-day lifetime; assert it is comfortably in the future
    // rather than pinning the exact figure they return.
    expect($token->expiresAt->isAfter(now()->addDay()))->toBeTrue();
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

it('stores a real token on the integration and reuses it on the next call', function () {
    ['clientId' => $clientId, 'clientSecret' => $clientSecret, 'customerId' => $customerId] = uberSandboxCredentials();

    $restaurant = adminOrderRestaurant('uberlive');

    $integration = DeliveryIntegration::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => DeliveryProviderName::Uber,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'customer_id' => $customerId,
        'status' => DeliveryIntegrationStatus::Disconnected,
    ]);

    $service = app(UberDirectTokenService::class);
    $first = $service->freshAccessToken($integration);

    expect($first)->toBeString()->not->toBeEmpty();
    expect($integration->fresh()->status)->toBe(DeliveryIntegrationStatus::Connected);

    // The second call must be served from storage — proving we don't burn the
    // 100/hour grant limit on every dispatch.
    expect($service->freshAccessToken($integration->fresh()))->toBe($first);
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

it('reports a rejected secret rather than throwing something opaque', function () {
    ['clientId' => $clientId] = uberSandboxCredentials();

    expect(fn () => app(UberDirectTokenService::class)->requestToken($clientId, 'definitely-not-the-secret'))
        ->toThrow(DeliveryProviderException::class, 'rejected the Client Secret');
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

it('reports an unrecognized client id distinctly from a bad secret', function () {
    expect(fn () => app(UberDirectTokenService::class)->requestToken('not-a-real-client-id', 'nope'))
        ->toThrow(DeliveryProviderException::class, 'does not recognize this Client ID');
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

/**
 * The one that matters: a real, priced, deliverable quote from Uber for a real
 * pair of addresses. This is the gate the plan puts in front of the checkout
 * rework — until it passes, nothing downstream is standing on verified ground.
 *
 * A quote creates nothing and costs nothing.
 */
it('gets a real priced quote from the Uber sandbox', function () {
    ['clientId' => $clientId, 'clientSecret' => $clientSecret, 'customerId' => $customerId] = uberSandboxCredentials();

    $restaurant = adminOrderRestaurant('uberquote');
    $restaurant->forceFill([
        'street' => '350 S 200 E',
        'city' => 'Salt Lake City',
        'state' => 'UT',
        'postal_code' => '84111',
        'phone' => '8015551234',
        'delivery_enabled' => true,
    ])->save();

    DeliveryIntegration::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => DeliveryProviderName::Uber,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'customer_id' => $customerId,
        'status' => DeliveryIntegrationStatus::Connected,
    ]);

    $quote = app(UberDirectProvider::class)->quote(new DeliveryQuoteRequest(
        restaurant: $restaurant->fresh(),
        dropoffAddress: [
            'street' => '201 S Main St',
            'street2' => '',
            'city' => 'Salt Lake City',
            'state' => 'UT',
            'postal_code' => '84111',
            'country' => 'US',
        ],
        subtotalCents: 1400,
        tipCents: 0,
        customerName: 'Test Customer',
        customerPhone: '8015555678',
    ));

    expect($quote->feeCents)->toBeGreaterThan(0);
    expect($quote->externalQuoteId)->toBeString()->not->toBeEmpty();
    expect($quote->expiresAt)->not->toBeNull();

    // Uber documents a 15-minute quote life — the number §0's countdown is
    // built on. Assert the shape of that claim rather than the exact minute.
    expect($quote->expiresAt->isAfter(now()))->toBeTrue();
    expect($quote->expiresAt->isBefore(now()->addMinutes(30)))->toBeTrue();
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

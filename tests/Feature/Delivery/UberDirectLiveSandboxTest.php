<?php

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryIntegration;
use App\Services\Delivery\DeliveryQuoteRequest;
use App\Services\Delivery\UberDirect\UberDirectProvider;
use App\Services\Delivery\UberDirect\UberDirectProvisioningService;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

// The global Http::preventStrayRequests() guard (tests/Pest.php) would block
// the real sandbox calls this opt-in suite exists to make.
beforeEach(fn () => Http::preventStrayRequests(false));

/**
 * Opt-in LIVE integration test — makes real calls to Uber's auth endpoint and
 * is skipped unless the platform credentials are present:
 *
 *   UBER_DIRECT_CLIENT_ID=...
 *   UBER_DIRECT_CLIENT_SECRET=...
 *   UBER_DIRECT_CUSTOMER_ID=...   (the ROOT organization id)
 *
 * Get them from direct.uber.com -> Management -> Developer. Sandbox credentials
 * create no real deliveries, and minting a token has no side effect beyond
 * Uber's 100-per-hour grant cap.
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
 *
 * ONE EXCEPTION, ONE STEP FURTHER GATED: the org-provisioning test below
 * creates a PERMANENT sub-organization (Uber cannot delete orgs via API), so
 * it additionally requires UBER_DIRECT_ALLOW_ORG_CREATE=1. Run it deliberately
 * and rarely. The 2026-08-05 probe org is 9dbc3452-fdc4-498f-b261-d76dd576fb37
 * ("Plateful Sandbox Probe") — reuse it before creating another.
 */

/**
 * @return array{clientId: string, clientSecret: string, customerId: string}|null
 */
function uberSandboxCredentials(): ?array
{
    $clientId = (string) config('services.uber_direct.client_id');
    $clientSecret = (string) config('services.uber_direct.client_secret');
    $customerId = (string) config('services.uber_direct.customer_id');

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

const UBER_SKIP_REASON = 'Set UBER_DIRECT_CLIENT_ID, _CLIENT_SECRET and _CUSTOMER_ID to run the live Uber Direct sandbox test.';

it('mints a real platform access token from the Uber sandbox', function () {
    $token = app(UberDirectTokenService::class)->requestToken();

    expect($token->accessToken)->toBeString()->not->toBeEmpty();

    // Uber documents a 30-day lifetime; assert it is comfortably in the future
    // rather than pinning the exact figure they return.
    expect($token->expiresAt->isAfter(now()->addDay()))->toBeTrue();
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

it('mints a real organizations-scoped token', function () {
    // The umbrella model stands on this scope: if the account loses it,
    // provisioning is dead even though deliveries still work.
    $token = app(UberDirectTokenService::class)
        ->requestToken(UberDirectTokenService::SCOPE_ORGANIZATIONS);

    expect($token->accessToken)->toBeString()->not->toBeEmpty();
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

it('caches the platform token across calls', function () {
    $service = app(UberDirectTokenService::class);
    $first = $service->freshAccessToken();

    expect($first)->toBeString()->not->toBeEmpty();

    // The second call must be served from cache — proving we don't burn the
    // 100/hour grant limit on every dispatch.
    expect($service->freshAccessToken())->toBe($first);
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

it('reports a rejected secret rather than throwing something opaque', function () {
    config(['services.uber_direct.client_secret' => 'definitely-not-the-secret']);

    expect(fn () => app(UberDirectTokenService::class)->requestToken())
        ->toThrow(DeliveryProviderException::class, 'rejected the platform Client Secret');
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

it('reports an unrecognized client id distinctly from a bad secret', function () {
    config(['services.uber_direct.client_id' => 'not-a-real-client-id']);
    config(['services.uber_direct.client_secret' => 'nope']);

    expect(fn () => app(UberDirectTokenService::class)->requestToken())
        ->toThrow(DeliveryProviderException::class, 'does not recognize the platform Client ID');
})->skip(uberSandboxMissing(...), UBER_SKIP_REASON);

/**
 * The one that matters: a real, priced, deliverable quote from Uber for a real
 * pair of addresses. A quote creates nothing and costs nothing.
 */
it('gets a real priced quote from the Uber sandbox', function () {
    ['customerId' => $customerId] = uberSandboxCredentials();

    $restaurant = adminOrderRestaurant('uberquote');
    $restaurant->forceFill([
        'street' => '350 S 200 E',
        'city' => 'Salt Lake City',
        'state' => 'UT',
        'postal_code' => '84111',
        'phone' => '8015551234',
        'delivery_enabled' => true,
    ])->save();

    // The root org quotes exactly like a provisioned sub-org (verified live
    // 2026-08-05 against the probe sub-org), so this stays side-effect-free.
    DeliveryIntegration::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => DeliveryProviderName::Uber,
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

/**
 * Creates a PERMANENT sandbox sub-organization — Uber cannot delete orgs via
 * the API, so this is double-gated behind UBER_DIRECT_ALLOW_ORG_CREATE=1.
 */
it('provisions a real sub-organization under the root account', function () {
    $restaurant = adminOrderRestaurant('uberorg');
    $restaurant->forceFill(['name' => 'Plateful Sandbox Probe'])->save();

    $integration = app(UberDirectProvisioningService::class)->provisionOrganizationFor($restaurant);

    expect($integration->status)->toBe(DeliveryIntegrationStatus::Connected);
    expect($integration->customer_id)->toBeString()->not->toBeEmpty();
})->skip(
    fn (): bool => uberSandboxMissing() || env('UBER_DIRECT_ALLOW_ORG_CREATE') !== '1',
    'Set UBER_DIRECT_ALLOW_ORG_CREATE=1 (plus the platform creds) to run the org-creation live test — it creates a PERMANENT sandbox org.',
);

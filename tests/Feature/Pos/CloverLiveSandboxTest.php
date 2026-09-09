<?php

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Models\PosIntegration;
use App\Services\Pos\Clover\CloverClient;
use App\Services\Pos\Clover\CloverPosProvider;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

// The global Http::preventStrayRequests() guard (tests/Pest.php) would block
// the real sandbox calls this opt-in suite exists to make.
beforeEach(fn () => Http::preventStrayRequests(false));

/**
 * Opt-in LIVE integration test — makes real calls to the Clover SANDBOX and is
 * skipped unless you provide a sandbox merchant's API token + merchant id:
 *
 *   CLOVER_ENVIRONMENT=sandbox
 *   CLOVER_SANDBOX_ACCESS_TOKEN=...   # an API token from the sandbox merchant
 *                                     # (Setup -> API Tokens on the test merchant)
 *                                     # with Orders R/W, Payments W, Merchant R
 *   CLOVER_SANDBOX_MERCHANT_ID=...    # that sandbox merchant's id
 *
 * Run just this file:
 *   php artisan test tests/Feature/Pos/CloverLiveSandboxTest.php
 *
 * It is NOT part of the normal suite (no creds -> skipped), so CI stays
 * deterministic and offline.
 *
 * NOTE: the credential lookup and skip condition are deferred into the test
 * lifecycle on purpose. `.env` is loaded when the application boots, which
 * happens in setUp() — long after Pest collects this file. Reading env at the
 * top level yields null even when the credentials ARE set, which silently
 * skipped this test unconditionally.
 */
function cloverSandboxToken(): ?string
{
    return env('CLOVER_SANDBOX_ACCESS_TOKEN') ?: null;
}

function cloverSandboxMerchant(): ?string
{
    return env('CLOVER_SANDBOX_MERCHANT_ID') ?: null;
}

function cloverSandboxMissing(): bool
{
    return cloverSandboxToken() === null || cloverSandboxMerchant() === null;
}

it('creates a real order in the Clover sandbox and can read it back', function () {
    $liveToken = cloverSandboxToken();
    $liveMerchant = cloverSandboxMerchant();

    config()->set('services.clover.environment', 'sandbox');

    $restaurant = adminOrderRestaurant('cloverlive');
    $order = makeOrder($restaurant, ['notes' => 'Live sandbox test — ignore']);
    $order->items()->update(['notes' => 'Extra napkins']);

    $integration = PosIntegration::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => PosProviderName::Clover,
        'external_merchant_id' => $liveMerchant,
        'location_id' => $liveMerchant,
        'access_token' => $liveToken,
        'refresh_token' => null,
        // Far-future so the provider uses the static sandbox token directly and
        // does not attempt a refresh (a dashboard API token does not rotate).
        'token_expires_at' => now()->addYear(),
        'status' => PosIntegrationStatus::Connected,
    ]);

    $result = app(CloverPosProvider::class)->pushOrder($order->load('items'), $integration);

    expect($result->success)->toBeTrue();
    expect($result->ticketId)->toBeString()->not->toBeEmpty();

    // Read it back from Clover to prove it actually landed. The Stripe payment
    // recorded against it is NOT observable here: Clover only computes
    // `paymentState` when the `payments` expansion is requested, and that
    // expansion needs Payments READ, which the app deliberately does not ask
    // for. Verified 2026-09-09 instead via the sandbox Merchant Dashboard,
    // where pushed tickets list as "Paid" with an "External Payment" line.
    $readBack = app(CloverClient::class)
        ->authed($liveToken)
        ->get("/v3/merchants/{$liveMerchant}/orders/{$result->ticketId}", ['expand' => 'lineItems']);

    expect($readBack->successful())->toBeTrue();
    expect($readBack->json('id'))->toBe($result->ticketId);
    expect($readBack->json('note'))->toBe('Plateful #'.$order->number.' · Alice Customer · Pickup — Live sandbox test — ignore');
    expect($readBack->json('lineItems.elements'))->toHaveCount(1);
    expect($readBack->json('lineItems.elements.0.note'))->toBe('Extra napkins');
})->skip(
    cloverSandboxMissing(...),
    'Set CLOVER_SANDBOX_ACCESS_TOKEN and CLOVER_SANDBOX_MERCHANT_ID to run the live Clover sandbox test.'
);

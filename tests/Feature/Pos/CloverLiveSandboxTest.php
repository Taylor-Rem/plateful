<?php

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Models\PosIntegration;
use App\Services\Pos\Clover\CloverClient;
use App\Services\Pos\Clover\CloverPosProvider;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

/**
 * Opt-in LIVE integration test — makes real calls to the Clover SANDBOX and is
 * skipped unless you provide a sandbox merchant's API token + merchant id:
 *
 *   CLOVER_ENVIRONMENT=sandbox
 *   CLOVER_SANDBOX_ACCESS_TOKEN=...   # an API token from the sandbox merchant
 *                                     # (Setup -> API Tokens on the test merchant)
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
    $order = makeOrder($restaurant);

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

    // Read it back from Clover to prove it actually landed.
    $readBack = app(CloverClient::class)
        ->authed($liveToken)
        ->get("/v3/merchants/{$liveMerchant}/orders/{$result->ticketId}");

    expect($readBack->successful())->toBeTrue();
    expect($readBack->json('id'))->toBe($result->ticketId);
})->skip(
    cloverSandboxMissing(...),
    'Set CLOVER_SANDBOX_ACCESS_TOKEN and CLOVER_SANDBOX_MERCHANT_ID to run the live Clover sandbox test.'
);

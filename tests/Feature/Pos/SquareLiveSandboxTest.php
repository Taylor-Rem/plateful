<?php

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Models\PosIntegration;
use App\Services\Pos\Square\SquareClient;
use App\Services\Pos\Square\SquarePosProvider;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

/**
 * Opt-in LIVE integration test — makes real calls to the Square SANDBOX and is
 * skipped unless you provide a sandbox seller's access token + location id:
 *
 *   SQUARE_ENVIRONMENT=sandbox
 *   SQUARE_SANDBOX_ACCESS_TOKEN=...   # "Sandbox Access Token" from the app dashboard
 *   SQUARE_SANDBOX_LOCATION_ID=...    # a location id from that sandbox account
 *
 * Run just this file:
 *   php artisan test tests/Feature/Pos/SquareLiveSandboxTest.php
 *
 * It is NOT part of the normal suite (no creds → skipped), so CI stays
 * deterministic and offline.
 */
$liveToken = env('SQUARE_SANDBOX_ACCESS_TOKEN');
$liveLocation = env('SQUARE_SANDBOX_LOCATION_ID');

it('creates a real order in the Square sandbox and can read it back', function () use ($liveToken, $liveLocation) {
    config()->set('services.square.environment', 'sandbox');

    $restaurant = adminOrderRestaurant('squarelive');
    $order = makeOrder($restaurant);

    $integration = PosIntegration::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => PosProviderName::Square,
        'location_id' => $liveLocation,
        'access_token' => $liveToken,
        'refresh_token' => null,
        // Far-future so the provider uses the static sandbox token directly and
        // does not attempt a refresh (sandbox tokens don't rotate).
        'token_expires_at' => now()->addYear(),
        'status' => PosIntegrationStatus::Connected,
    ]);

    $result = app(SquarePosProvider::class)->pushOrder($order->load('items'), $integration);

    expect($result->success)->toBeTrue();
    expect($result->ticketId)->toBeString()->not->toBeEmpty();

    // Read it back from Square to prove it actually landed.
    $readBack = app(SquareClient::class)
        ->authed($liveToken)
        ->get("/v2/orders/{$result->ticketId}");

    expect($readBack->successful())->toBeTrue();
    expect($readBack->json('order.id'))->toBe($result->ticketId);
    expect($readBack->json('order.reference_id'))->toBe($order->number);
})->skip(
    empty($liveToken) || empty($liveLocation),
    'Set SQUARE_SANDBOX_ACCESS_TOKEN and SQUARE_SANDBOX_LOCATION_ID to run the live Square sandbox test.'
);

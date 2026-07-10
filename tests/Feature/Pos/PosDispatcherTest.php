<?php

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Exceptions\PosTokenExpiredException;
use App\Models\PosIntegration;
use App\Services\Pos\PosDispatcher;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';
require_once __DIR__.'/PosTestHelpers.php';

it('returns not_configured when the restaurant has no integration', function () {
    $r = adminOrderRestaurant('nopos');
    $order = makeOrder($r);

    $result = (new PosDispatcher([]))->dispatch($order);

    expect($result->success)->toBeFalse()
        ->and($result->failureReason)->toBe('not_configured');
});

it('returns not_configured when the only integration is disconnected', function () {
    $r = adminOrderRestaurant('discpos');
    PosIntegration::factory()->disconnected()->create(['restaurant_id' => $r->id]);
    $order = makeOrder($r);

    $dispatcher = new PosDispatcher([
        PosProviderName::Square->value => fakePosProvider(),
    ]);

    expect($dispatcher->shouldPush($r))->toBeFalse();
    expect($dispatcher->dispatch($order)->failureReason)->toBe('not_configured');
});

it('returns provider_unavailable when no adapter is registered for the provider', function () {
    $r = adminOrderRestaurant('noadapter');
    PosIntegration::factory()->create(['restaurant_id' => $r->id]);
    $order = makeOrder($r);

    $result = (new PosDispatcher([]))->dispatch($order);

    expect($result->success)->toBeFalse()
        ->and($result->provider)->toBe(PosProviderName::Square)
        ->and($result->failureReason)->toBe('provider_unavailable');
});

it('pushes the order and returns the ticket id from the adapter', function () {
    $r = adminOrderRestaurant('happypos');
    PosIntegration::factory()->create(['restaurant_id' => $r->id]);
    $order = makeOrder($r);

    $dispatcher = new PosDispatcher([
        PosProviderName::Square->value => fakePosProvider(ticketId: 'SQ-777'),
    ]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeTrue()
        ->and($result->provider)->toBe(PosProviderName::Square)
        ->and($result->ticketId)->toBe('SQ-777');
});

it('marks the integration token_expired when the adapter reports an expired token', function () {
    $r = adminOrderRestaurant('expiredpos');
    $integration = PosIntegration::factory()->create(['restaurant_id' => $r->id]);
    $order = makeOrder($r);

    $dispatcher = new PosDispatcher([
        PosProviderName::Square->value => fakePosProvider(
            throwOnPush: PosTokenExpiredException::for(PosProviderName::Square),
        ),
    ]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeFalse()
        ->and($result->tokenExpired)->toBeTrue()
        ->and($result->failureReason)->toBe('token_expired');

    $integration->refresh();
    expect($integration->status)->toBe(PosIntegrationStatus::TokenExpired)
        ->and($integration->last_error)->toContain('expired');
});

it('returns a failed result when the adapter throws a generic exception', function () {
    $r = adminOrderRestaurant('errpos');
    PosIntegration::factory()->create(['restaurant_id' => $r->id]);
    $order = makeOrder($r);

    $dispatcher = new PosDispatcher([
        PosProviderName::Square->value => fakePosProvider(
            throwOnPush: new RuntimeException('connection timeout'),
        ),
    ]);

    $result = $dispatcher->dispatch($order);

    expect($result->success)->toBeFalse()
        ->and($result->tokenExpired)->toBeFalse()
        ->and($result->failureReason)->toBe('connection timeout');
});

it('skips adapters that do not support the restaurant', function () {
    $r = adminOrderRestaurant('unsupported');
    PosIntegration::factory()->create(['restaurant_id' => $r->id]);
    $order = makeOrder($r);

    $dispatcher = new PosDispatcher([
        PosProviderName::Square->value => fakePosProvider(supports: false),
    ]);

    expect($dispatcher->dispatch($order)->failureReason)->toBe('provider_unavailable');
});

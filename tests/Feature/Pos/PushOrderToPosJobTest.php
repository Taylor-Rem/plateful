<?php

use App\Enums\PosProviderName;
use App\Exceptions\PosProviderException;
use App\Jobs\PushOrderToPos;
use App\Models\OrderEvent;
use App\Models\PosIntegration;
use App\Services\Pos\PosDispatcher;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';
require_once __DIR__.'/PosTestHelpers.php';

it('records the ticket id, pushed_at and a success order event on success', function () {
    $r = adminOrderRestaurant('jobhappy');
    PosIntegration::factory()->create(['restaurant_id' => $r->id]);
    $order = makeOrder($r);

    $dispatcher = new PosDispatcher([
        PosProviderName::Square->value => fakePosProvider(ticketId: 'SQ-42'),
    ]);

    (new PushOrderToPos($order->id))->handle($dispatcher);

    $order->refresh();
    expect($order->pos_ticket_id)->toBe('SQ-42')
        ->and($order->pos_provider)->toBe(PosProviderName::Square)
        ->and($order->pos_pushed_at)->not->toBeNull()
        ->and($order->pos_push_failed_at)->toBeNull();

    $event = OrderEvent::query()->where('order_id', $order->id)->latest('id')->first();
    expect($event->note)->toContain('POS push succeeded')
        ->and($event->note)->toContain('SQ-42')
        ->and($event->from_status)->toBe($event->to_status);
});

it('is a no-op when the order already has a pos ticket id', function () {
    $r = adminOrderRestaurant('jobdone');
    PosIntegration::factory()->create(['restaurant_id' => $r->id]);
    $order = makeOrder($r, ['pos_ticket_id' => 'SQ-EXISTING']);

    $dispatcher = new PosDispatcher([
        PosProviderName::Square->value => fakePosProvider(
            throwOnPush: new RuntimeException('should never be called'),
        ),
    ]);

    (new PushOrderToPos($order->id))->handle($dispatcher);

    expect(OrderEvent::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('is a no-op when the restaurant has no connected integration', function () {
    $r = adminOrderRestaurant('jobnopos');
    $order = makeOrder($r);

    (new PushOrderToPos($order->id))->handle(new PosDispatcher([]));

    $order->refresh();
    expect($order->pos_ticket_id)->toBeNull()
        ->and($order->pos_push_failed_at)->toBeNull();
    expect(OrderEvent::query()->where('order_id', $order->id)->count())->toBe(0);
});

it('logs an attempt order event and throws to trigger a retry on generic failure', function () {
    $r = adminOrderRestaurant('jobretry');
    PosIntegration::factory()->create(['restaurant_id' => $r->id]);
    $order = makeOrder($r);

    $dispatcher = new PosDispatcher([
        PosProviderName::Square->value => fakePosProvider(
            throwOnPush: new RuntimeException('connection timeout'),
        ),
    ]);

    expect(fn () => (new PushOrderToPos($order->id))->handle($dispatcher))
        ->toThrow(PosProviderException::class);

    $order->refresh();
    expect($order->pos_ticket_id)->toBeNull()
        ->and($order->pos_push_failed_at)->toBeNull();

    $event = OrderEvent::query()->where('order_id', $order->id)->latest('id')->first();
    expect($event->note)->toContain('POS push attempt')
        ->and($event->note)->toContain('connection timeout')
        ->and($event->from_status)->toBe($event->to_status);
});

it('marks pos_push_failed_at and logs an order event when retries are exhausted', function () {
    $r = adminOrderRestaurant('jobfailed');
    $order = makeOrder($r);

    (new PushOrderToPos($order->id))->failed(PosProviderException::pushFailed('gave up'));

    $order->refresh();
    expect($order->pos_push_failed_at)->not->toBeNull();

    $event = OrderEvent::query()->where('order_id', $order->id)->latest('id')->first();
    expect($event->note)->toContain('POS push permanently failed')
        ->and($event->note)->toContain('gave up');
});

it('does not mark failure when the order was pushed successfully before exhaustion', function () {
    $r = adminOrderRestaurant('jobnofail');
    $order = makeOrder($r, ['pos_ticket_id' => 'SQ-OK']);

    (new PushOrderToPos($order->id))->failed(PosProviderException::pushFailed('stale retry'));

    expect($order->fresh()->pos_push_failed_at)->toBeNull();
});

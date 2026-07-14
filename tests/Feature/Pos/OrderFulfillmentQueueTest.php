<?php

use App\Enums\DeliveryMode;
use App\Jobs\DispatchDeliveryForOrder;
use App\Jobs\PushOrderToPos;
use App\Models\PosIntegration;
use App\Services\CartManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/../Storefront/CartTestHelpers.php';
require_once __DIR__.'/../Storefront/CheckoutTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
    Bus::fake([PushOrderToPos::class, DispatchDeliveryForOrder::class]);
});

/**
 * Add the fixture item to a cart and start checkout, returning nothing —
 * follow with payLatestCheckout() to materialize the order.
 *
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $checkoutOverrides
 */
function startCheckout(array $fixture, array $checkoutOverrides = []): void
{
    $r = $fixture['restaurant'];

    $first = test()->post("http://{$r->subdomain}.plateful.test/cart/items/{$fixture['item']->id}", [
        'option_ids' => [$fixture['size_medium']->id, $fixture['top_pepperoni']->id],
    ]);
    $cookie = cartCookieFrom($first);

    fakeCheckoutSession();
    test()->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", array_merge([
            'customer_name' => 'Alice Customer',
            'customer_email' => 'alice@example.test',
            'type' => 'pickup',
            'tip_preset' => '0',
        ], $checkoutOverrides));
}

it('queues a POS push after checkout when the restaurant has a connected integration', function () {
    $f = cartFixture();
    PosIntegration::factory()->create(['restaurant_id' => $f['restaurant']->id]);

    startCheckout($f);
    $order = payLatestCheckout();

    Bus::assertDispatched(PushOrderToPos::class, fn (PushOrderToPos $job) => $job->orderId === $order->id);
});

it('does not queue a POS push when no integration is connected', function () {
    $f = cartFixture();

    startCheckout($f);
    payLatestCheckout();

    Bus::assertNotDispatched(PushOrderToPos::class);
});

it('does not queue a POS push when the integration is disconnected', function () {
    $f = cartFixture();
    PosIntegration::factory()->disconnected()->create(['restaurant_id' => $f['restaurant']->id]);

    startCheckout($f);
    payLatestCheckout();

    Bus::assertNotDispatched(PushOrderToPos::class);
});

it('queues delivery dispatch for delivery orders', function () {
    $f = cartFixture();
    // Self-delivery keeps this test about queueing, not about quoting.
    $f['restaurant']->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::SelfDelivery,
    ]);

    startCheckout($f, [
        'type' => 'delivery',
        'delivery_address' => [
            'street' => '123 Main',
            'city' => 'NYC',
            'state' => 'NY',
            'postal_code' => '10001',
        ],
    ]);
    $order = payLatestCheckout();

    Bus::assertDispatched(DispatchDeliveryForOrder::class, fn (DispatchDeliveryForOrder $job) => $job->orderId === $order->id);
});

it('does not queue delivery dispatch for pickup orders', function () {
    $f = cartFixture();

    startCheckout($f);
    payLatestCheckout();

    Bus::assertNotDispatched(DispatchDeliveryForOrder::class);
});

it('does not double-queue when checkout completion is replayed', function () {
    $f = cartFixture();
    PosIntegration::factory()->create(['restaurant_id' => $f['restaurant']->id]);

    startCheckout($f);
    $first = payLatestCheckout();
    $second = payLatestCheckout();

    expect($second->id)->toBe($first->id);
    Bus::assertDispatchedTimes(PushOrderToPos::class, 1);
});

<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/ApiCheckoutHelpers.php';

beforeEach(function () {
    Mail::fake();
});

/**
 * @return array{0: array<string, mixed>, 1: Order, 2: string}
 */
function placedGuestOrder(): array
{
    $f = cartFixture();
    [, $token] = addPepViaApi($f);
    fakePaymentIntents('succeeded');
    $pendingId = test()->withHeader('X-Cart-Token', $token)
        ->postJson(apiRestaurantBase($f['restaurant']).'/checkout/intents', pickupCheckoutPayload())
        ->json('data.pendingCheckoutId');
    $confirmation = test()->withHeader('X-Cart-Token', $token)
        ->postJson(apiRestaurantBase($f['restaurant'])."/checkout/{$pendingId}/confirm")
        ->json('data.confirmationToken');
    test()->flushHeaders();

    return [$f, Order::firstOrFail(), $confirmation];
}

test('a guest reads their order with the confirmation token header', function () {
    [$f, $order, $confirmation] = placedGuestOrder();
    $url = apiRestaurantBase($f['restaurant']).'/orders/'.$order->number;

    $this->withHeader('X-Order-Token', $confirmation)->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.number', $order->number)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.delivery', null)
        ->assertJsonStructure(['data' => ['id', 'number', 'status', 'type', 'totalCents', 'items', 'placedAt']]);

    $this->flushHeaders();
    $this->getJson($url)->assertNotFound();
    $this->withHeader('X-Order-Token', 'wrong')->getJson($url)->assertNotFound();
});

test('the signed-in owner reads their order with a bearer token; others cannot', function () {
    [$f, $order] = placedGuestOrder();
    $owner = User::factory()->create();
    $order->update(['user_id' => $owner->id]);
    $url = apiRestaurantBase($f['restaurant']).'/orders/'.$order->number;

    $this->withToken(apiTokenFor($owner))->getJson($url)->assertOk();
    forgetApiGuards();
    $this->withToken(apiTokenFor(User::factory()->create()))->getJson($url)->assertNotFound();
});

test('an order number at another restaurant is a 404', function () {
    [, $order, $confirmation] = placedGuestOrder();
    $other = cartFixture('luigis');

    $this->withHeader('X-Order-Token', $confirmation)
        ->getJson(apiRestaurantBase($other['restaurant']).'/orders/'.$order->number)
        ->assertNotFound();
});

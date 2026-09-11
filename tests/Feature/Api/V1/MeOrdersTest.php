<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/ApiCheckoutHelpers.php';

beforeEach(function () {
    Mail::fake();
});

/**
 * Place a guest order at the fixture restaurant and hand it to $user.
 *
 * @param  array<string, mixed>  $fixture
 */
function orderFor(User $user, array $fixture): Order
{
    [, $token] = addPepViaApi($fixture);
    fakePaymentIntents('succeeded');
    $pendingId = test()->withHeader('X-Cart-Token', $token)
        ->postJson(apiRestaurantBase($fixture['restaurant']).'/checkout/intents', pickupCheckoutPayload())
        ->assertCreated()
        ->json('data.pendingCheckoutId');
    test()->withHeader('X-Cart-Token', $token)
        ->postJson(apiRestaurantBase($fixture['restaurant'])."/checkout/{$pendingId}/confirm")
        ->assertCreated();
    test()->flushHeaders();

    $order = Order::withoutTenantScope()->latest('id')->firstOrFail();
    $order->forceFill(['user_id' => $user->id])->save();

    return $order;
}

test('order history spans every restaurant and is newest first with pagination meta', function () {
    $user = User::factory()->create();
    $marcos = cartFixture('marcos');
    $luigis = cartFixture('luigis');
    $first = orderFor($user, $marcos);
    $second = orderFor($user, $luigis);
    orderFor(User::factory()->create(), $marcos);

    $response = $this->withToken(apiTokenFor($user))->getJson(API_BASE.'/me/orders');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.number', $second->number)
        ->assertJsonPath('data.0.restaurantSubdomain', 'luigis')
        ->assertJsonPath('data.0.itemCount', 1)
        ->assertJsonPath('data.0.totalCents', 1400)
        ->assertJsonPath('data.0.deliveryStatus', null)
        ->assertJsonPath('data.1.number', $first->number)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonStructure(['data' => [['id', 'number', 'status', 'type', 'totalCents', 'itemCount', 'placedAt', 'restaurantName', 'restaurantSubdomain', 'restaurantLogoThumbUrl']]]);
});

test('an order is readable by number without a restaurant, but only by its owner', function () {
    $user = User::factory()->create();
    $order = orderFor($user, cartFixture());

    $this->withToken(apiTokenFor($user))->getJson(API_BASE.'/me/orders/'.$order->number)
        ->assertOk()
        ->assertJsonPath('data.number', $order->number)
        ->assertJsonPath('data.items.0.name', 'Pep');

    forgetApiGuards();
    $this->withToken(apiTokenFor(User::factory()->create()))->getJson(API_BASE.'/me/orders/'.$order->number)
        ->assertNotFound();

    forgetApiGuards();
    $this->getJson(API_BASE.'/me/orders')->assertUnauthorized();
});

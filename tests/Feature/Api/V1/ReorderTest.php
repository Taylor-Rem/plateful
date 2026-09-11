<?php

use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/ApiCheckoutHelpers.php';

beforeEach(function () {
    Mail::fake();
});

/**
 * A guest pickup order with the fixture's medium pepperoni pizza.
 *
 * @return array{0: array<string, mixed>, 1: Order, 2: string}
 */
function guestOrderForReorder(): array
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

    return [$f, Order::withoutTenantScope()->latest('id')->firstOrFail(), $confirmation];
}

test('reorder rebuilds a fresh guest cart with the same selections at today\'s prices', function () {
    [$f, $order, $confirmation] = guestOrderForReorder();

    $response = $this->withHeader('X-Order-Token', $confirmation)
        ->postJson(apiRestaurantBase($f['restaurant'])."/orders/{$order->number}/reorder");

    $response->assertCreated()
        ->assertJsonPath('data.skipped', [])
        ->assertJsonPath('data.cart.itemCount', 1)
        ->assertJsonPath('data.cart.items.0.menuItemName', 'Pep')
        ->assertJsonPath('data.cart.items.0.unitPriceCents', 1400)
        ->assertJsonStructure(['data' => ['cart', 'cartToken', 'skipped']]);

    expect($response->json('data.cartToken'))->not->toBeNull()
        ->and($response->json('data.cart.items.0.selectedOptionIds'))->toEqualCanonicalizing([$f['size_medium']->id, $f['top_pepperoni']->id]);
});

test('reorder reports lines it could not bring back instead of dropping them', function () {
    [$f, $order, $confirmation] = guestOrderForReorder();
    $f['item']->update(['is_available' => false]);

    $this->withHeader('X-Order-Token', $confirmation)
        ->postJson(apiRestaurantBase($f['restaurant'])."/orders/{$order->number}/reorder")
        ->assertCreated()
        ->assertJsonPath('data.cart', null)
        ->assertJsonPath('data.skipped.0.name', 'Pep')
        ->assertJsonPath('data.skipped.0.reason', 'No longer on the menu.');
});

test('reorder skips a line whose options no longer validate', function () {
    [$f, $order, $confirmation] = guestOrderForReorder();
    $f['size_medium']->delete();

    $this->withHeader('X-Order-Token', $confirmation)
        ->postJson(apiRestaurantBase($f['restaurant'])."/orders/{$order->number}/reorder")
        ->assertCreated()
        ->assertJsonPath('data.skipped.0.reason', 'Its options have changed — add it again from the menu.');
});

test('a signed-in owner reorders into their own cart; strangers get a 404', function () {
    [$f, $order] = guestOrderForReorder();
    $owner = User::factory()->create();
    $order->forceFill(['user_id' => $owner->id])->save();
    $url = apiRestaurantBase($f['restaurant'])."/orders/{$order->number}/reorder";

    $this->postJson($url)->assertNotFound();
    $this->withToken(apiTokenFor(User::factory()->create()))->postJson($url)->assertNotFound();
    forgetApiGuards();

    $this->withToken(apiTokenFor($owner))->postJson($url)
        ->assertCreated()
        ->assertJsonPath('data.cartToken', null)
        ->assertJsonPath('data.cart.itemCount', 1);
});

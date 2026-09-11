<?php

use App\Models\Cart;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

require_once __DIR__.'/ApiCheckoutHelpers.php';

beforeEach(function () {
    Mail::fake();
});

test('adding an item creates a guest cart and hands back its token', function () {
    $f = cartFixture();

    [$response, $token] = addPepViaApi($f);

    $response->assertCreated()
        ->assertJsonPath('data.itemCount', 1)
        ->assertJsonPath('data.subtotalCents', 1400)
        ->assertJsonPath('data.items.0.menuItemName', 'Pep')
        ->assertJsonStructure(['data' => ['id', 'itemCount', 'subtotalCents', 'items'], 'cartToken']);

    expect($token)->not->toBe('')
        ->and(Cart::query()->where('token', $token)->value('restaurant_id'))->toBe($f['restaurant']->id);

    $this->withHeader('X-Cart-Token', $token)->getJson(apiRestaurantBase($f['restaurant']).'/cart')
        ->assertOk()
        ->assertJsonPath('data.itemCount', 1)
        ->assertJsonPath('cartToken', $token);
});

test('the cart is empty until something is added', function () {
    $f = cartFixture();

    $this->getJson(apiRestaurantBase($f['restaurant']).'/cart')
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('cartToken', null);
});

test('a cart token from one restaurant never reaches another restaurant', function () {
    $a = cartFixture('marcos');
    $b = cartFixture('luigis');

    [, $tokenA] = addPepViaApi($a);

    $this->withHeader('X-Cart-Token', $tokenA)->getJson(apiRestaurantBase($b['restaurant']).'/cart')
        ->assertOk()
        ->assertJsonPath('data', null);

    [$responseB, $tokenB] = addPepViaApi($b, $tokenA);

    $responseB->assertCreated()->assertJsonPath('data.itemCount', 1);
    expect($tokenB)->not->toBe($tokenA)
        ->and(Cart::withoutTenantScope()->where('token', $tokenA)->firstOrFail()->items()->count())->toBe(1);
});

test('lines can be updated, replaced, removed, and the cart cleared', function () {
    $f = cartFixture();
    $base = apiRestaurantBase($f['restaurant']);
    [$response, $token] = addPepViaApi($f);
    $lineId = $response->json('data.items.0.id');
    $headers = ['X-Cart-Token' => $token];

    $this->withHeaders($headers)->patchJson("{$base}/cart/items/{$lineId}", ['quantity' => 3])
        ->assertOk()->assertJsonPath('data.itemCount', 3);

    $this->withHeaders($headers)->putJson("{$base}/cart/items/{$lineId}", [
        'quantity' => 1,
        'option_ids' => [$f['size_small']->id],
        'notes' => 'no cheese',
    ])->assertOk()
        ->assertJsonPath('data.items.0.unitPriceCents', 1000)
        ->assertJsonPath('data.items.0.notes', 'no cheese');

    $this->withHeaders($headers)->postJson("{$base}/cart/items/{$f['simple']->id}")->assertCreated()
        ->assertJsonPath('data.itemCount', 2);

    $this->withHeaders($headers)->deleteJson("{$base}/cart/items/{$lineId}")
        ->assertOk()->assertJsonPath('data.itemCount', 1);

    $this->withHeaders($headers)->deleteJson("{$base}/cart")
        ->assertOk()->assertJsonPath('data.itemCount', 0);
});

test('invalid selections are a validation error', function () {
    $f = cartFixture();

    $this->postJson(apiRestaurantBase($f['restaurant']).'/cart/items/'.$f['item']->id, [
        'option_ids' => [$f['other_template_option']->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['option_ids']);
});

test('a line in someone else\'s cart is a 404', function () {
    $f = cartFixture();
    [$response] = addPepViaApi($f);
    $lineId = $response->json('data.items.0.id');

    $this->patchJson(apiRestaurantBase($f['restaurant'])."/cart/items/{$lineId}", ['quantity' => 2])
        ->assertNotFound();
});

test('a signed-in customer gets a user-bound cart with no token', function () {
    $f = cartFixture();
    $user = User::factory()->create();
    $bearer = apiTokenFor($user);

    $response = $this->withToken($bearer)->postJson(apiRestaurantBase($f['restaurant']).'/cart/items/'.$f['simple']->id);

    $response->assertCreated()->assertJsonPath('cartToken', null);
    expect(Cart::query()->where('user_id', $user->id)->count())->toBe(1);

    forgetApiGuards();
    $this->withToken($bearer)->getJson(apiRestaurantBase($f['restaurant']).'/cart')
        ->assertOk()->assertJsonPath('data.itemCount', 1);
});

test('signing in with a guest cart token merges the cart into the account', function () {
    $f = cartFixture();
    $user = User::factory()->create(['email' => 'ada@example.com']);
    [, $token] = addPepViaApi($f);

    $bearer = $this->withHeader('X-Cart-Token', $token)->postJson(API_BASE.'/auth/login', [
        'email' => 'ada@example.com', 'password' => 'password', 'device_name' => 'iPhone',
    ])->assertOk()->json('data.token');

    expect(Cart::query()->where('token', $token)->value('user_id'))->toBe($user->id);

    $this->withToken($bearer)->getJson(apiRestaurantBase($f['restaurant']).'/cart')
        ->assertOk()
        ->assertJsonPath('data.itemCount', 1)
        ->assertJsonPath('cartToken', null);
});

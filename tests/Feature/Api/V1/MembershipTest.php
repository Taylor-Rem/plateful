<?php

use App\Models\LoyaltyPoints;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;

require_once __DIR__.'/ApiHelpers.php';

test('membership reflects favourite, consent, points, and totals for the signed-in customer', function () {
    $user = User::factory()->create();
    $r = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $bearer = apiTokenFor($user);
    $base = API_BASE.'/restaurants/marcos';

    $this->withToken($bearer)->getJson("{$base}/me")
        ->assertOk()
        ->assertJsonPath('data', ['isFavorite' => false, 'marketingOptedIn' => false, 'loyaltyPoints' => 0, 'totalOrders' => 0, 'totalSpentCents' => 0, 'lastOrderedAt' => null]);

    RestaurantCustomer::create(['user_id' => $user->id, 'restaurant_id' => $r->id, 'total_orders' => 3, 'total_spent_cents' => 4500, 'last_ordered_at' => now()]);
    LoyaltyPoints::create(['user_id' => $user->id, 'restaurant_id' => $r->id, 'points' => 45]);

    $this->withToken($bearer)->putJson("{$base}/favorite")
        ->assertOk()->assertJsonPath('data.isFavorite', true)->assertJsonPath('data.loyaltyPoints', 45)->assertJsonPath('data.totalOrders', 3);

    $this->withToken($bearer)->putJson("{$base}/favorite")->assertOk();
    expect($user->favoriteRestaurants()->count())->toBe(1);

    $this->withToken($bearer)->putJson("{$base}/marketing-consent", ['opted_in' => true])
        ->assertOk()->assertJsonPath('data.marketingOptedIn', true);
    expect(RestaurantCustomer::query()->where('user_id', $user->id)->first()->isEmailOptedIn())->toBeTrue();

    $this->withToken($bearer)->putJson("{$base}/marketing-consent", ['opted_in' => false])
        ->assertOk()->assertJsonPath('data.marketingOptedIn', false);

    $this->withToken($bearer)->deleteJson("{$base}/favorite")
        ->assertOk()->assertJsonPath('data.isFavorite', false);
});

test('favourites list only live restaurants and membership needs a token', function () {
    $user = User::factory()->create();
    $live = Restaurant::factory()->create(['subdomain' => 'live', 'name' => 'Live']);
    $closed = Restaurant::factory()->suspended()->create(['subdomain' => 'closed']);
    $user->favoriteRestaurants()->attach([$live->id, $closed->id]);

    $this->withToken(apiTokenFor($user))->getJson(API_BASE.'/me/favorites')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.subdomain', 'live');

    forgetApiGuards();
    $this->getJson(API_BASE.'/restaurants/live/me')->assertUnauthorized();
});

test('the wallet aggregates points and standing per restaurant without pooling', function () {
    $user = User::factory()->create();
    $a = Restaurant::factory()->create(['subdomain' => 'a', 'name' => 'A']);
    $b = Restaurant::factory()->create(['subdomain' => 'b', 'name' => 'B']);
    RestaurantCustomer::create(['user_id' => $user->id, 'restaurant_id' => $a->id, 'total_orders' => 2, 'total_spent_cents' => 3000, 'last_ordered_at' => now()->subDay()]);
    RestaurantCustomer::create(['user_id' => $user->id, 'restaurant_id' => $b->id, 'total_orders' => 1, 'total_spent_cents' => 1200, 'last_ordered_at' => now()]);
    LoyaltyPoints::create(['user_id' => $user->id, 'restaurant_id' => $a->id, 'points' => 30]);

    $response = $this->withToken(apiTokenFor($user))->getJson(API_BASE.'/me/wallet');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.restaurant.subdomain', 'b')
        ->assertJsonPath('data.0.loyaltyPoints', 0)
        ->assertJsonPath('data.1.restaurant.subdomain', 'a')
        ->assertJsonPath('data.1.loyaltyPoints', 30)
        ->assertJsonPath('data.1.pointsPerDollar', 1)
        ->assertJsonPath('data.1.totalSpentCents', 3000);
});

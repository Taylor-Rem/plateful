<?php

use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

function regRestaurant(string $sub = 'regtest'): Restaurant
{
    return Restaurant::create([
        'name' => 'Reg Test',
        'subdomain' => $sub,
        'email' => "hello@{$sub}.test",
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

test('registration screen can be rendered on tenant host', function () {
    $restaurant = regRestaurant();

    $response = $this->get("http://{$restaurant->subdomain}.plateful.test/register");

    $response->assertOk();
});

test('registration is 404 on admin host', function () {
    $response = $this->get('http://admin.plateful.test/register');

    $response->assertNotFound();
});

test('new users can register on a tenant host and a restaurant_customer row is created', function () {
    $restaurant = regRestaurant();

    $response = $this->post("http://{$restaurant->subdomain}.plateful.test/register", [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/');

    $user = User::query()->where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->is_super_admin)->toBeFalse();

    // Decision F: signup creates the (user, restaurant) association immediately.
    $pivot = RestaurantCustomer::query()
        ->where('user_id', $user->id)
        ->where('restaurant_id', $restaurant->id)
        ->first();
    expect($pivot)->not->toBeNull();
    expect($pivot->first_ordered_at)->toBeNull();
    expect((int) $pivot->total_orders)->toBe(0);
});

test('an email already registered globally cannot register again at any restaurant', function () {
    $marcos = regRestaurant('marcos');
    $bobs = regRestaurant('bobs');

    // First signup at marcos (auto-logs the user in).
    $this->post("http://{$marcos->subdomain}.plateful.test/register", [
        'name' => 'Test User',
        'email' => 'same@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    // Log out so the second request runs as a guest — otherwise Fortify's
    // RedirectIfAuthenticated middleware short-circuits the register flow.
    $this->post("http://{$marcos->subdomain}.plateful.test/logout");

    // Trying to register the same email at bobs must fail validation
    // (email is globally unique on Plateful now).
    $resp = $this->post("http://{$bobs->subdomain}.plateful.test/register", [
        'name' => 'Other User',
        'email' => 'same@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $resp->assertSessionHasErrors('email');
    expect(User::query()->where('email', 'same@example.com')->count())->toBe(1);
});

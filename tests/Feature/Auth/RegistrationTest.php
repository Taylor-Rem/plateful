<?php

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

function regRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'Reg Test',
        'subdomain' => 'regtest',
        'email' => 'hello@regtest.test',
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

test('new users can register on a tenant host', function () {
    $restaurant = regRestaurant();

    $response = $this->post("http://{$restaurant->subdomain}.plateful.test/register", [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/dashboard');

    $user = User::query()->where('email', 'test@example.com')->first();
    expect($user)->not->toBeNull();
    expect($user->restaurant_id)->toBe($restaurant->id);
    expect($user->role)->toBe(UserRole::Customer);
});

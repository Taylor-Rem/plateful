<?php

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;

function makeTenantRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'Auth Test Pizza',
        'subdomain' => 'authtest',
        'email' => 'hello@authtest.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

function makeTenantCustomer(Restaurant $restaurant, array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'restaurant_id' => $restaurant->id,
        'role' => UserRole::Customer,
    ], $attrs));
}

function tenantBase(Restaurant $restaurant): string
{
    return "http://{$restaurant->subdomain}.plateful.test";
}

test('login screen can be rendered', function () {
    $restaurant = makeTenantRestaurant();

    $response = $this->get(tenantBase($restaurant).'/login');

    $response->assertOk();
});

test('users can authenticate using the login screen', function () {
    $restaurant = makeTenantRestaurant();
    $user = makeTenantCustomer($restaurant);

    $response = $this->post(tenantBase($restaurant).'/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/dashboard');
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $restaurant = makeTenantRestaurant();
    $user = User::factory()->withTwoFactor()->create([
        'restaurant_id' => $restaurant->id,
        'role' => UserRole::Customer,
    ]);

    $response = $this->post(tenantBase($restaurant).'/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/two-factor-challenge');
    $response->assertSessionHas('login.id', $user->id);
    $this->assertGuest();
});

test('users can not authenticate with invalid password', function () {
    $restaurant = makeTenantRestaurant();
    $user = makeTenantCustomer($restaurant);

    $this->post(tenantBase($restaurant).'/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $restaurant = makeTenantRestaurant();
    $user = makeTenantCustomer($restaurant);

    $response = $this->actingAs($user)
        ->post(tenantBase($restaurant).'/logout');

    $response->assertRedirect();
    $this->assertGuest();
});

test('users are rate limited', function () {
    $restaurant = makeTenantRestaurant();
    $user = makeTenantCustomer($restaurant);

    RateLimiter::increment(md5('login'.implode('|', [$user->email, '127.0.0.1'])), amount: 5);

    $response = $this->post(tenantBase($restaurant).'/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertTooManyRequests();
});

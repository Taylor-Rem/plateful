<?php

use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
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
    $user = User::factory()->create($attrs);

    RestaurantCustomer::create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
    ]);

    return $user;
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
    $response->assertRedirect('/');
});

test('users with two factor enabled are redirected to two factor challenge', function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());

    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $restaurant = makeTenantRestaurant();
    $user = User::factory()->withTwoFactor()->create();
    RestaurantCustomer::create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
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

test('a Plateful account signed up at one restaurant can log in at another', function () {
    $marcos = Restaurant::create([
        'name' => 'Marco', 'subdomain' => 'marcos', 'email' => 'm@m.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);
    $bobs = Restaurant::create([
        'name' => 'Bob', 'subdomain' => 'bobs', 'email' => 'b@b.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);

    // User exists with a pivot only at marcos.
    $user = makeTenantCustomer($marcos, ['email' => 'shared@example.test']);

    // Logging into bobs storefront should succeed — one Plateful account works
    // at every Plateful restaurant.
    $response = $this->post(tenantBase($bobs).'/login', [
        'email' => 'shared@example.test',
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect('/');
});

test('an admin user with no order history can still log into a tenant storefront', function () {
    $restaurant = makeTenantRestaurant();

    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant->id, ['role' => 'admin']);

    $response = $this->post(tenantBase($restaurant).'/login', [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($admin);
    $response->assertRedirect('/');
});

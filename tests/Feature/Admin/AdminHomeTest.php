<?php

use App\Models\Restaurant;
use App\Models\User;

const HOME_ADMIN_BASE = 'http://admin.plateful.test';

function homeRestaurant(string $sub = 'r1'): Restaurant
{
    return Restaurant::create([
        'name' => "R-{$sub}",
        'subdomain' => $sub,
        'email' => "hello@{$sub}.test",
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

test('super admin home shows all restaurants', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    homeRestaurant('alpha');
    homeRestaurant('beta');

    $response = $this->actingAs($superAdmin)->get(HOME_ADMIN_BASE.'/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Home')
        ->where('isSuperAdmin', true)
        ->has('restaurants', 2));
});

test('admin with one restaurant is auto-redirected to its dashboard', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = homeRestaurant('solo');
    $admin->restaurants()->attach($restaurant->id);

    $response = $this->actingAs($admin)->get(HOME_ADMIN_BASE.'/');

    $response->assertRedirect('/solo/dashboard');
});

test('admin with multiple restaurants sees the picker', function () {
    $admin = User::factory()->admin()->create();
    $r1 = homeRestaurant('one');
    $r2 = homeRestaurant('two');
    $admin->restaurants()->attach([$r1->id, $r2->id]);

    $response = $this->actingAs($admin)->get(HOME_ADMIN_BASE.'/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Home')
        ->where('isSuperAdmin', false)
        ->has('restaurants', 2));
});

test('a user with no restaurant_user pivot and not super_admin is forbidden on admin host', function () {
    // Under the platform-wide-accounts model, "admin" status is conferred only
    // by membership in the restaurant_user pivot or by is_super_admin.
    // A bare User row (formerly role=Admin/restaurant_id=null) is just a
    // plain Plateful customer and cannot access admin routes.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(HOME_ADMIN_BASE.'/');

    $response->assertForbidden();
});

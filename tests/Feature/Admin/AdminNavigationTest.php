<?php

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;

const NAV_ADMIN_BASE = 'http://admin.plateful.test';

function navRestaurant(string $sub): Restaurant
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

test('staff members get the props that hide admin-only nav', function () {
    $staff = User::factory()->create();
    $restaurant = navRestaurant('navjoint');
    $staff->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Staff->value]);

    $response = $this->actingAs($staff)->get(NAV_ADMIN_BASE.'/navjoint/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('currentRestaurantRole', 'staff')
        ->where('auth.isSuperAdmin', false));
});

test('restaurant admins get the admin role and their switcher restaurants', function () {
    $admin = User::factory()->admin()->create();
    $one = navRestaurant('navjoint');
    $two = navRestaurant('othernav');
    $admin->restaurants()->attach([$one->id, $two->id]);

    $response = $this->actingAs($admin)->get(NAV_ADMIN_BASE.'/navjoint/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('currentRestaurantRole', 'admin')
        ->where('auth.isSuperAdmin', false)
        ->has('adminRestaurants', 2)
        ->where('adminRestaurants.0.subdomain', 'navjoint')
        ->where('adminRestaurants.1.subdomain', 'othernav'));
});

test('super admins see every restaurant in the switcher, capped at ten', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    foreach (range(1, 12) as $i) {
        navRestaurant(sprintf('nav%02d', $i));
    }

    $response = $this->actingAs($superAdmin)->get(NAV_ADMIN_BASE.'/nav01/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('currentRestaurantRole', 'admin')
        ->where('auth.isSuperAdmin', true)
        ->has('adminRestaurants', 10));
});

test('the restaurant picker home does not carry switcher data', function () {
    $admin = User::factory()->admin()->create();
    $one = navRestaurant('navjoint');
    $two = navRestaurant('othernav');
    $admin->restaurants()->attach([$one->id, $two->id]);

    $response = $this->actingAs($admin)->get(NAV_ADMIN_BASE.'/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Home')
        ->missing('adminRestaurants'));
});

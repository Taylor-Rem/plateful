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

test('admin with no restaurants sees no-access page', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)->get(HOME_ADMIN_BASE.'/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Admin/NoAccess'));
});

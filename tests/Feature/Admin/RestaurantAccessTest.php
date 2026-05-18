<?php

use App\Models\Restaurant;
use App\Models\User;

const ACCESS_ADMIN_BASE = 'http://admin.plateful.test';

function accessRestaurant(string $sub): Restaurant
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

test('admin with pivot access can view their restaurant dashboard', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = accessRestaurant('marcos');
    $admin->restaurants()->attach($restaurant->id);

    $response = $this->actingAs($admin)->get(ACCESS_ADMIN_BASE.'/marcos/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/TenantAdmin/Dashboard')
        ->where('restaurant.subdomain', 'marcos'));
});

test('admin without pivot access gets 403', function () {
    $admin = User::factory()->admin()->create();
    accessRestaurant('marcos');

    $response = $this->actingAs($admin)->get(ACCESS_ADMIN_BASE.'/marcos/dashboard');

    $response->assertForbidden();
});

test('super admin can access any restaurant', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    accessRestaurant('marcos');

    $response = $this->actingAs($superAdmin)->get(ACCESS_ADMIN_BASE.'/marcos/dashboard');

    $response->assertOk();
});

test('unknown restaurant subdomain returns 404', function () {
    $admin = User::factory()->superAdmin()->create();

    $response = $this->actingAs($admin)->get(ACCESS_ADMIN_BASE.'/nonexistent/dashboard');

    $response->assertNotFound();
});

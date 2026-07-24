<?php

use App\Models\Restaurant;
use App\Models\User;

const SHARED_PROPS_ADMIN_BASE = 'http://admin.plateful.test';

function sharedPropsRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'Shared Props Pizzeria',
        'subdomain' => 'sharedprops',
        'email' => 'hello@sharedprops.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

test('super admins get auth.isSuperAdmin true on admin pages', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    sharedPropsRestaurant();

    $response = $this->actingAs($superAdmin)->get(SHARED_PROPS_ADMIN_BASE.'/sharedprops/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('auth.isSuperAdmin', true));
});

test('restaurant admins get auth.isSuperAdmin false', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = sharedPropsRestaurant();
    $admin->restaurants()->attach($restaurant->id);

    $response = $this->actingAs($admin)->get(SHARED_PROPS_ADMIN_BASE.'/sharedprops/dashboard');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('auth.isSuperAdmin', false));
});

test('guests get auth.isSuperAdmin false on the login page', function () {
    $response = $this->get(SHARED_PROPS_ADMIN_BASE.'/login');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->where('auth.isSuperAdmin', false));
});

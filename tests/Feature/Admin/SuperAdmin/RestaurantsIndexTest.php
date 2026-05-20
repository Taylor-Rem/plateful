<?php

use App\Models\Restaurant;
use App\Models\User;

const SUPER_INDEX_BASE = 'http://admin.plateful.test';

test('super admin can list all restaurants including deactivated', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $active = Restaurant::factory()->create(['subdomain' => 'active-one', 'name' => 'Active One']);
    $inactive = Restaurant::factory()->inactive()->create(['subdomain' => 'inactive-one', 'name' => 'Inactive One']);

    $response = $this->actingAs($superAdmin)
        ->get(SUPER_INDEX_BASE.'/super/restaurants');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/SuperAdmin/Restaurants/Index')
        ->has('restaurants', 2));
});

test('non-super admin cannot reach the restaurants index', function () {
    $admin = User::factory()->admin()->create();

    $response = $this->actingAs($admin)
        ->get(SUPER_INDEX_BASE.'/super/restaurants');

    $response->assertForbidden();
});

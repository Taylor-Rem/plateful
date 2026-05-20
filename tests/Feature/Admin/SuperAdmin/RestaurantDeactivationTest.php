<?php

use App\Models\Restaurant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

const SUPER_DEACT_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('super admin can deactivate an active restaurant', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_DEACT_BASE."/super/restaurants/{$restaurant->subdomain}/deactivate");

    $response->assertRedirect();
    expect($restaurant->fresh()->is_active)->toBeFalse();
});

test('super admin can reactivate a deactivated restaurant', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->inactive()->create(['subdomain' => 'marcos']);

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_DEACT_BASE."/super/restaurants/{$restaurant->subdomain}/activate");

    $response->assertRedirect();
    expect($restaurant->fresh()->is_active)->toBeTrue();
});

test('deactivated storefront returns 503 with Unavailable component', function () {
    Restaurant::factory()->inactive()->create(['subdomain' => 'marcos']);

    $response = $this->get('http://marcos.plateful.test/');

    expect($response->status())->toBe(503);
    $response->assertInertia(fn ($page) => $page->component('Storefront/Unavailable'));
});

test('deactivated storefront does not bind tenant', function () {
    Restaurant::factory()->inactive()->create(['subdomain' => 'marcos']);

    $this->get('http://marcos.plateful.test/');

    expect(app(CurrentTenant::class)->check())->toBeFalse();
});

test('after reactivation the storefront returns 200', function () {
    $restaurant = Restaurant::factory()->inactive()->create(['subdomain' => 'marcos']);

    $first = $this->get('http://marcos.plateful.test/');
    expect($first->status())->toBe(503);

    $restaurant->update(['is_active' => true]);

    $second = $this->get('http://marcos.plateful.test/');
    expect($second->status())->toBe(200);
});

test('restaurant admin can still access admin dashboard for deactivated restaurant', function () {
    $restaurant = Restaurant::factory()->inactive()->create(['subdomain' => 'marcos']);
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant);

    $response = $this->actingAs($admin)
        ->get(SUPER_DEACT_BASE."/{$restaurant->subdomain}/dashboard");

    $response->assertOk();
});

test('deactivating one restaurant does not affect another', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $marcos = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $bobs = Restaurant::factory()->create(['subdomain' => 'bobs']);

    $this->actingAs($superAdmin)
        ->post(SUPER_DEACT_BASE."/super/restaurants/{$marcos->subdomain}/deactivate");

    expect($marcos->fresh()->is_active)->toBeFalse();
    expect($bobs->fresh()->is_active)->toBeTrue();

    $response = $this->get('http://bobs.plateful.test/');
    expect($response->status())->toBe(200);
});

test('non-super admin cannot deactivate', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);

    $response = $this->actingAs($admin)
        ->post(SUPER_DEACT_BASE."/super/restaurants/{$restaurant->subdomain}/deactivate");

    $response->assertForbidden();
    expect($restaurant->fresh()->is_active)->toBeTrue();
});

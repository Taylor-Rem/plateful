<?php

use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Tenancy\CurrentTenant;

const SUPER_DELETE_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('super admin can soft delete a restaurant', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);

    $response = $this->actingAs($superAdmin)
        ->delete(SUPER_DELETE_BASE."/super/restaurants/{$restaurant->subdomain}");

    $response->assertRedirect(route('admin.super.restaurants.index'));
    expect(Restaurant::find($restaurant->id))->toBeNull();
    expect(Restaurant::withTrashed()->find($restaurant->id)->trashed())->toBeTrue();
});

test('a soft-deleted restaurant drops off the roster but shows in the deleted list', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $live = Restaurant::factory()->create(['subdomain' => 'bobs']);
    $gone = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $gone->delete();

    $this->actingAs($superAdmin)
        ->get(SUPER_DELETE_BASE.'/super/restaurants')
        ->assertInertia(fn ($page) => $page
            ->component('Admin/SuperAdmin/Restaurants/Index')
            ->has('restaurants', 1)
            ->where('restaurants.0.subdomain', 'bobs')
            ->has('deletedRestaurants', 1)
            ->where('deletedRestaurants.0.subdomain', 'marcos')
        );
});

test('a soft-deleted restaurant storefront no longer binds the tenant', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $restaurant->delete();

    $this->get('http://marcos.plateful.test/');

    expect(app(CurrentTenant::class)->check())->toBeFalse();
});

test('super admin can restore a soft-deleted restaurant', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $restaurant->delete();

    $response = $this->actingAs($superAdmin)
        ->post(SUPER_DELETE_BASE."/super/restaurants/{$restaurant->subdomain}/restore");

    $response->assertRedirect();
    expect(Restaurant::find($restaurant->id))->not->toBeNull();
    expect(Restaurant::find($restaurant->id)->trashed())->toBeFalse();
});

test('super admin can permanently delete a soft-deleted restaurant with no orders', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $restaurant->delete();

    $response = $this->actingAs($superAdmin)
        ->delete(SUPER_DELETE_BASE."/super/restaurants/{$restaurant->subdomain}/force");

    $response->assertRedirect(route('admin.super.restaurants.index'));
    expect(Restaurant::withTrashed()->find($restaurant->id))->toBeNull();
});

test('permanent delete is blocked when the restaurant has order history', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    Order::factory()->for($restaurant)->create();
    $restaurant->delete();

    $response = $this->actingAs($superAdmin)
        ->delete(SUPER_DELETE_BASE."/super/restaurants/{$restaurant->subdomain}/force");

    $response->assertRedirect(route('admin.super.restaurants.index'));
    $response->assertSessionHas('error');
    // Still present as a soft-deleted row — its records are preserved.
    expect(Restaurant::withTrashed()->find($restaurant->id))->not->toBeNull();
    expect(Restaurant::withTrashed()->find($restaurant->id)->trashed())->toBeTrue();
});

test('non-super admin cannot delete a restaurant', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);

    $this->actingAs($admin)
        ->delete(SUPER_DELETE_BASE."/super/restaurants/{$restaurant->subdomain}")
        ->assertForbidden();

    expect(Restaurant::find($restaurant->id))->not->toBeNull();
});

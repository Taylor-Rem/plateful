<?php

use App\Enums\PosIntegrationStatus;
use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use App\Models\User;

const POS_ADMIN_HOST = 'http://admin.plateful.test';

/**
 * @return array{0: User, 1: Restaurant}
 */
function posPageOwnerAndRestaurant(): array
{
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'pizzajoint', 'is_active' => true]);
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    return [$owner, $restaurant];
}

it('shows provider statuses to a restaurant admin', function () {
    [$owner, $restaurant] = posPageOwnerAndRestaurant();

    $this->actingAs($owner)
        ->get(POS_ADMIN_HOST."/{$restaurant->subdomain}/settings/pos")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/PosIntegrations')
            ->has('providers', 2)
            ->where('providers.0.provider', 'square')
            ->where('providers.0.status', 'disconnected')
            ->where('providers.0.available', false)
            ->where('providers.1.provider', 'clover')
        );
});

it('reflects a connected integration in the provider status', function () {
    [$owner, $restaurant] = posPageOwnerAndRestaurant();
    PosIntegration::factory()->create([
        'restaurant_id' => $restaurant->id,
        'last_error' => null,
    ]);

    $this->actingAs($owner)
        ->get(POS_ADMIN_HOST."/{$restaurant->subdomain}/settings/pos")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('providers.0.provider', 'square')
            ->where('providers.0.status', 'connected')
        );
});

it('is forbidden for staff members', function () {
    [$owner, $restaurant] = posPageOwnerAndRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->get(POS_ADMIN_HOST."/{$restaurant->subdomain}/settings/pos")
        ->assertForbidden();
});

it('adds an optional pos step to the onboarding checklist', function () {
    [$owner, $restaurant] = posPageOwnerAndRestaurant();
    $restaurant->update(['status' => RestaurantStatus::Approved]);

    $this->actingAs($owner)
        ->get(POS_ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('steps.4.key', 'pos')
            ->where('steps.4.required', false)
            ->where('steps.4.complete', false)
        );

    PosIntegration::factory()->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($owner)
        ->get(POS_ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertInertia(fn ($page) => $page->where('steps.4.complete', true));
});

it('does not mark the pos step complete for a disconnected integration', function () {
    [$owner, $restaurant] = posPageOwnerAndRestaurant();
    $restaurant->update(['status' => RestaurantStatus::Approved]);
    PosIntegration::factory()->create([
        'restaurant_id' => $restaurant->id,
        'status' => PosIntegrationStatus::Disconnected,
    ]);

    $this->actingAs($owner)
        ->get(POS_ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->where('steps.4.complete', false));
});

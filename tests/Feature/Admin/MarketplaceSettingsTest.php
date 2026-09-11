<?php

use App\Models\Restaurant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function marketplaceOwner(Restaurant $restaurant): User
{
    $owner = User::factory()->create();
    $owner->restaurants()->attach($restaurant->id, ['role' => 'admin']);

    return $owner;
}

test('the settings page offers the cuisine taxonomy and current marketplace fields', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos', 'cuisine_tags' => ['pizza'], 'marketplace_listed' => false]);

    $this->actingAs(marketplaceOwner($restaurant))
        ->get('http://admin.plateful.test/marcos/settings')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/TenantAdmin/Settings')
            ->where('restaurant.cuisineTags', ['pizza'])
            ->where('restaurant.marketplaceListed', false)
            ->has('cuisineOptions.pizza'));
});

test('owners can opt out of the marketplace and choose cuisine tags', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);

    $this->actingAs(marketplaceOwner($restaurant))
        ->put('http://admin.plateful.test/marcos/settings', [
            'name' => $restaurant->name,
            'marketplace_listed' => false,
            'cuisine_tags' => ['italian', 'pizza', 'italian'],
        ])->assertRedirect()->assertSessionHasNoErrors();

    $restaurant->refresh();
    expect($restaurant->marketplace_listed)->toBeFalse()
        ->and($restaurant->cuisine_tags)->toBe(['italian', 'pizza']);
});

test('unknown cuisine tags and more than five are rejected', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $owner = marketplaceOwner($restaurant);

    $this->actingAs($owner)
        ->from('http://admin.plateful.test/marcos/settings')
        ->put('http://admin.plateful.test/marcos/settings', ['name' => $restaurant->name, 'cuisine_tags' => ['martian']])
        ->assertSessionHasErrors(['cuisine_tags.0']);

    $this->actingAs($owner)
        ->from('http://admin.plateful.test/marcos/settings')
        ->put('http://admin.plateful.test/marcos/settings', ['name' => $restaurant->name, 'cuisine_tags' => ['italian', 'pizza', 'mexican', 'thai', 'sushi', 'ramen']])
        ->assertSessionHasErrors(['cuisine_tags']);
});

test('a form without the marketplace fields leaves them untouched', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos', 'cuisine_tags' => ['thai'], 'marketplace_listed' => false]);

    $this->actingAs(marketplaceOwner($restaurant))
        ->put('http://admin.plateful.test/marcos/settings', ['name' => 'Renamed'])
        ->assertRedirect();

    $restaurant->refresh();
    expect($restaurant->marketplace_listed)->toBeFalse()
        ->and($restaurant->cuisine_tags)->toBe(['thai']);
});

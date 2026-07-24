<?php

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;

const SUPER_DOMAIN_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function domainPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Renamed Restaurant',
        'subdomain' => 'renamed',
        'custom_domain' => null,
    ], $overrides);
}

test('super admin can rename a restaurant and change its subdomain', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant', 'name' => 'Testaurant']);

    $response = $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'name' => 'Marios Trattoria',
            'subdomain' => 'marios',
        ]));

    $response->assertRedirect(route('admin.super.restaurants.show', 'marios'));
    $response->assertSessionHasNoErrors();

    $fresh = $restaurant->fresh();
    expect($fresh->name)->toBe('Marios Trattoria');
    expect($fresh->subdomain)->toBe('marios');
});

test('the storefront resolves on the new subdomain and no longer on the old one', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'subdomain' => 'marios',
        ]));

    expect($this->get('http://marios.plateful.test/')->status())->toBe(200);

    // The old host no longer maps to any restaurant — it 404s.
    expect($this->get('http://testaurant.plateful.test/')->status())->toBe(404);
});

test('super admin can set a custom domain that resolves the storefront', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'subdomain' => 'testaurant',
            'custom_domain' => 'marios.com',
        ]))
        ->assertSessionHasNoErrors();

    expect($restaurant->fresh()->custom_domain)->toBe('marios.com');
    expect($this->get('http://marios.com/')->status())->toBe(200);
});

test('a blank custom domain clears it', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant', 'custom_domain' => 'old.com']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'subdomain' => 'testaurant',
            'custom_domain' => '',
        ]))
        ->assertSessionHasNoErrors();

    expect($restaurant->fresh()->custom_domain)->toBeNull();
});

test('changing to a subdomain already taken by another restaurant fails', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    Restaurant::factory()->create(['subdomain' => 'bobs']);
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'subdomain' => 'bobs',
        ]))
        ->assertSessionHasErrors('subdomain');

    expect($restaurant->fresh()->subdomain)->toBe('testaurant');
});

test('keeping the same subdomain is allowed (self-ignored uniqueness)', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'name' => 'Just A Rename',
            'subdomain' => 'testaurant',
        ]))
        ->assertSessionHasNoErrors();

    expect($restaurant->fresh()->name)->toBe('Just A Rename');
});

test('a reserved subdomain is rejected', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'subdomain' => 'admin',
        ]))
        ->assertSessionHasErrors('subdomain');
});

test('an invalid subdomain format is rejected', function (string $bad) {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'subdomain' => $bad,
        ]))
        ->assertSessionHasErrors('subdomain');
})->with([
    'spaces' => 'mario s',
    'leading hyphen' => '-marios',
    'underscore' => 'mar_ios',
]);

test('a mixed-case subdomain is normalized to lowercase and accepted', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'subdomain' => 'Marios',
        ]))
        ->assertSessionHasNoErrors();

    expect($restaurant->fresh()->subdomain)->toBe('marios');
});

test('a custom domain under the platform primary domain is rejected', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'subdomain' => 'testaurant',
            'custom_domain' => 'evil.plateful.test',
        ]))
        ->assertSessionHasErrors('custom_domain');
});

test('a custom domain already used by another restaurant is rejected', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    Restaurant::factory()->create(['subdomain' => 'bobs', 'custom_domain' => 'taken.com']);
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);

    $this->actingAs($superAdmin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload([
            'subdomain' => 'testaurant',
            'custom_domain' => 'taken.com',
        ]))
        ->assertSessionHasErrors('custom_domain');
});

test('a tenant admin cannot change the domain', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'testaurant']);
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant, ['role' => RestaurantRole::Admin->value]);

    $this->actingAs($admin)
        ->put(SUPER_DOMAIN_BASE."/super/restaurants/{$restaurant->subdomain}/domain", domainPayload())
        ->assertForbidden();

    expect($restaurant->fresh()->subdomain)->toBe('testaurant');
});

<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('storefront home renders Inertia page for a tenant', function () {
    config(['platform.primary_domain' => 'plateful.test']);

    $restaurant = Restaurant::create([
        'name' => "Marco's Pizza",
        'subdomain' => 'marcos',
        'email' => 'hello@marcos.test',
        'primary_color' => '#b91c1c',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);

    $category = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas',
    ]);

    MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'menu_category_id' => $category->id,
        'name' => 'Margherita',
        'slug' => 'margherita',
        'price_cents' => 1399,
    ]);

    $response = $this->get('http://marcos.plateful.test/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Storefront/Home')
        ->where('restaurant.name', "Marco's Pizza")
        ->where('restaurant.subdomain', 'marcos')
        ->has('categories.0.items.0')
    );
});

test('storefront omits unavailable items and empty categories, ordered by position', function () {
    config(['platform.primary_domain' => 'plateful.test']);

    $restaurant = Restaurant::create([
        'name' => "Marco's Pizza",
        'subdomain' => 'marcos',
        'email' => 'hello@marcos.test',
        'primary_color' => '#b91c1c',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);

    $second = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Sides',
        'slug' => 'sides',
        'position' => 1,
    ]);

    $first = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas',
        'position' => 0,
    ]);

    $empty = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Specials',
        'slug' => 'specials',
        'position' => 2,
    ]);

    MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'menu_category_id' => $first->id,
        'name' => 'Margherita',
        'slug' => 'margherita',
        'price_cents' => 1399,
        'is_available' => true,
        'position' => 0,
    ]);

    MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'menu_category_id' => $first->id,
        'name' => 'Hidden',
        'slug' => 'hidden',
        'price_cents' => 999,
        'is_available' => false,
        'position' => 1,
    ]);

    MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'menu_category_id' => $second->id,
        'name' => 'Knots',
        'slug' => 'knots',
        'price_cents' => 599,
        'is_available' => true,
        'position' => 0,
    ]);

    $response = $this->get('http://marcos.plateful.test/');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('categories', 2)
        ->where('categories.0.name', 'Pizzas')
        ->where('categories.1.name', 'Sides')
        ->has('categories.0.items', 1)
        ->where('categories.0.items.0.name', 'Margherita')
    );

    expect($empty->fresh())->not->toBeNull();
});

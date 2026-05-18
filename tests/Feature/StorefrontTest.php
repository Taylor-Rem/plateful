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

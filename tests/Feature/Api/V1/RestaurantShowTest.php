<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Tenancy\CurrentTenant;

require_once __DIR__.'/ApiHelpers.php';

test('show returns the full restaurant detail by subdomain', function () {
    $r = Restaurant::factory()->create(['subdomain' => 'testaurant', 'cuisine_tags' => ['italian'], 'latitude' => 40.2, 'longitude' => -111.6]);

    $this->getJson(API_BASE.'/restaurants/testaurant')
        ->assertOk()
        ->assertJsonPath('data.id', $r->id)
        ->assertJsonPath('data.cuisineTags', ['italian'])
        ->assertJsonPath('data.latitude', 40.2)
        ->assertJsonPath('data.marketplaceListed', true)
        ->assertJsonStructure(['data' => ['hoursByDay', 'isOpen', 'deliveryEnabled', 'publicUrl']]);
});

test('an opted-out restaurant is still reachable by subdomain', function () {
    Restaurant::factory()->create(['subdomain' => 'quiet', 'marketplace_listed' => false]);

    $this->getJson(API_BASE.'/restaurants/quiet')
        ->assertOk()
        ->assertJsonPath('data.marketplaceListed', false);
});

test('a restaurant that is not live is a 404', function () {
    Restaurant::factory()->approved()->create(['subdomain' => 'soon']);

    $this->getJson(API_BASE.'/restaurants/soon')->assertNotFound();
    $this->getJson(API_BASE.'/restaurants/soon/menu')->assertNotFound();
    $this->getJson(API_BASE.'/restaurants/nowhere')->assertNotFound();
});

test('menu returns active categories with available items only', function () {
    $r = Restaurant::factory()->create(['subdomain' => 'testaurant']);
    app(CurrentTenant::class)->set($r);

    $mains = MenuCategory::create(['restaurant_id' => $r->id, 'name' => 'Mains', 'slug' => 'mains', 'position' => 1, 'is_active' => true]);
    $hidden = MenuCategory::create(['restaurant_id' => $r->id, 'name' => 'Hidden', 'slug' => 'hidden', 'position' => 2, 'is_active' => false]);
    $empty = MenuCategory::create(['restaurant_id' => $r->id, 'name' => 'Empty', 'slug' => 'empty', 'position' => 3, 'is_active' => true]);

    MenuItem::create(['restaurant_id' => $r->id, 'menu_category_id' => $mains->id, 'name' => 'Lasagna', 'slug' => 'lasagna', 'price_cents' => 1499, 'is_available' => true, 'position' => 1]);
    MenuItem::create(['restaurant_id' => $r->id, 'menu_category_id' => $mains->id, 'name' => 'Sold out', 'slug' => 'sold-out', 'price_cents' => 999, 'is_available' => false, 'position' => 2]);
    MenuItem::create(['restaurant_id' => $r->id, 'menu_category_id' => $hidden->id, 'name' => 'Secret', 'slug' => 'secret', 'price_cents' => 100, 'is_available' => true, 'position' => 1]);

    app(CurrentTenant::class)->clear();

    $response = $this->getJson(API_BASE.'/restaurants/testaurant/menu');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Mains')
        ->assertJsonPath('data.0.items.*.name', ['Lasagna'])
        ->assertJsonPath('data.0.items.0.priceCents', 1499)
        ->assertJsonStructure(['data' => [['id', 'name', 'slug', 'items' => [['id', 'name', 'priceCents', 'groups', 'ingredients']]]]]);
});

test('menu is scoped to the restaurant in the path', function () {
    $a = Restaurant::factory()->create(['subdomain' => 'a']);
    $b = Restaurant::factory()->create(['subdomain' => 'b']);

    foreach ([$a, $b] as $r) {
        app(CurrentTenant::class)->set($r);
        $cat = MenuCategory::create(['restaurant_id' => $r->id, 'name' => "Menu {$r->subdomain}", 'slug' => 'menu', 'position' => 1, 'is_active' => true]);
        MenuItem::create(['restaurant_id' => $r->id, 'menu_category_id' => $cat->id, 'name' => "Dish {$r->subdomain}", 'slug' => 'dish', 'price_cents' => 500, 'is_available' => true, 'position' => 1]);
    }
    app(CurrentTenant::class)->clear();

    $this->getJson(API_BASE.'/restaurants/b/menu')
        ->assertOk()
        ->assertJsonPath('data.*.name', ['Menu b']);
});

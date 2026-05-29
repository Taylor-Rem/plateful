<?php

use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function sftRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => "Marco's Pizza",
        'subdomain' => 'marcos',
        'email' => 'hello@marcos.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

test('storefront response includes template and defaultSelectionIds for configurable items', function () {
    $r = sftRestaurant();

    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas',
        'position' => 0,
        'is_active' => true,
    ]);

    $tpl = ItemTemplate::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizza',
        'is_active' => true,
        'position' => 0,
    ]);
    $size = ItemTemplateGroup::create([
        'item_template_id' => $tpl->id, 'name' => 'Size', 'min_selections' => 1, 'max_selections' => 1, 'position' => 0,
    ]);
    $medium = ItemTemplateOption::create([
        'item_template_group_id' => $size->id, 'name' => 'Medium', 'price_delta_cents' => 0, 'is_available' => true, 'position' => 0,
    ]);

    $item = MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'item_template_id' => $tpl->id,
        'name' => 'Margherita',
        'slug' => 'margherita',
        'price_cents' => 1200,
        'is_available' => true,
        'position' => 0,
    ]);
    $item->defaultSelections()->sync([$medium->id]);

    $this->get('http://marcos.plateful.test/menu')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.items.0.name', 'Margherita')
            ->where('categories.0.items.0.template.name', 'Pizza')
            ->where('categories.0.items.0.defaultSelectionIds.0', $medium->id),
        );
});

test('items with no template have template null', function () {
    $r = sftRestaurant();

    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Drinks',
        'slug' => 'drinks',
        'position' => 0,
        'is_active' => true,
    ]);

    MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'item_template_id' => null,
        'name' => 'Soda',
        'slug' => 'soda',
        'price_cents' => 299,
        'is_available' => true,
        'position' => 0,
    ]);

    $this->get('http://marcos.plateful.test/menu')
        ->assertInertia(fn ($page) => $page
            ->where('categories.0.items.0.name', 'Soda')
            ->where('categories.0.items.0.template', null)
            ->where('categories.0.items.0.defaultSelectionIds', []),
        );
});

test('templateless items still appear in the storefront', function () {
    $r = sftRestaurant();

    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Drinks',
        'slug' => 'drinks',
        'position' => 0,
        'is_active' => true,
    ]);
    MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'name' => 'Water',
        'slug' => 'water',
        'price_cents' => 100,
        'is_available' => true,
        'position' => 0,
    ]);

    $this->get('http://marcos.plateful.test/menu')
        ->assertInertia(fn ($page) => $page->has('categories.0.items.0'));
});

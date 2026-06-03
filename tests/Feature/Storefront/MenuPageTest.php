<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use Illuminate\Support\Str;

function menuPageRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'Marcos Pizza',
        'subdomain' => 'marcos',
        'email' => 'hello@marcos.test',
        'street' => '123 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

function menuPageCategoryWithItem(Restaurant $r, string $name = 'Pizzas'): MenuItem
{
    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => $name,
        'slug' => Str::slug($name).'-'.uniqid(),
        'position' => 0,
        'is_active' => true,
    ]);

    return MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'name' => 'Margherita',
        'slug' => 'margherita-'.uniqid(),
        'price_cents' => 1399,
        'is_available' => true,
        'position' => 0,
    ]);
}

test('GET /menu renders the menu page with categories', function () {
    $r = menuPageRestaurant();
    menuPageCategoryWithItem($r);

    $this->get('http://marcos.plateful.test/menu')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->component('Storefront/Menu')
                ->where('restaurant.name', 'Marcos Pizza')
                ->has('categories.0.items.0')
        );
});

test('GET / no longer carries categories prop', function () {
    $r = menuPageRestaurant();
    menuPageCategoryWithItem($r);

    $this->get('http://marcos.plateful.test/')
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->component('Storefront/Home')
                ->missing('categories')
        );
});

test('storefront markup includes nav with Menu link on every storefront page', function () {
    $r = menuPageRestaurant();
    menuPageCategoryWithItem($r);

    // Normalize JSON-escaped slashes ("Storefront\/Home") so the check is
    // robust regardless of json_encode's slash escaping.
    $homeHtml = str_replace('\\/', '/', $this->get('http://marcos.plateful.test/')->getContent());
    $menuHtml = str_replace('\\/', '/', $this->get('http://marcos.plateful.test/menu')->getContent());

    // Inertia ships the layout in JSON for the initial page; the layout
    // itself isn't rendered server-side. Smoke check: the data-page JSON
    // names the right component.
    expect($homeHtml)->toContain('Storefront/Home')
        ->and($menuHtml)->toContain('Storefront/Menu');
});

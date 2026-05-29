<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;

function fiRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'Marcos Pizza',
        'subdomain' => 'marcos',
        'email' => 'hello@marcos.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

function fiCategory(Restaurant $r, bool $active = true): MenuCategory
{
    return MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas-'.uniqid(),
        'position' => 0,
        'is_active' => $active,
    ]);
}

function fiItem(MenuCategory $c, array $attrs = []): MenuItem
{
    return MenuItem::withoutTenantScope()->create(array_merge([
        'restaurant_id' => $c->restaurant_id,
        'menu_category_id' => $c->id,
        'name' => 'Margherita',
        'slug' => 'margherita-'.uniqid(),
        'price_cents' => 1399,
        'is_available' => true,
        'is_featured' => false,
        'position' => 0,
    ], $attrs));
}

test('home exposes featured items prop', function () {
    $r = fiRestaurant();
    $c = fiCategory($r);
    fiItem($c, ['name' => 'Plain', 'is_featured' => false]);
    fiItem($c, ['name' => 'Star', 'is_featured' => true, 'slug' => 'star-'.uniqid()]);

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->has('featuredItems', 1)
                ->where('featuredItems.0.name', 'Star')
                ->where('featuredItems.0.isFeatured', true)
        );
});

test('featured items respect availability and active category filters', function () {
    $r = fiRestaurant();
    $active = fiCategory($r, active: true);
    $inactive = fiCategory($r, active: false);

    fiItem($active, ['name' => 'Visible', 'is_featured' => true]);
    fiItem($active, ['name' => 'Unavailable', 'is_featured' => true, 'is_available' => false, 'slug' => 'una-'.uniqid()]);
    fiItem($inactive, ['name' => 'CategoryOff', 'is_featured' => true, 'slug' => 'cat-'.uniqid()]);

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page->has('featuredItems', 1)
                ->where('featuredItems.0.name', 'Visible')
        );
});

test('featured items cap at six', function () {
    $r = fiRestaurant();
    $c = fiCategory($r);
    foreach (range(1, 10) as $i) {
        fiItem($c, ['name' => "Item {$i}", 'is_featured' => true, 'slug' => "item-{$i}-".uniqid(), 'position' => $i]);
    }

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('featuredItems', 6));
});

test('admin can flag an item as featured via the menu edit endpoint', function () {
    $r = fiRestaurant();
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($r->id, ['role' => 'admin']);
    $c = fiCategory($r);
    $item = fiItem($c, ['is_featured' => false]);

    $this->actingAs($admin)
        ->put("http://{$r->subdomain}.plateful.test/admin/menu/items/{$item->id}", [
            'name' => $item->name,
            'menu_category_id' => $c->id,
            'price' => ($item->price_cents / 100),
            'is_available' => true,
            'is_featured' => true,
        ])
        ->assertRedirect();

    expect($item->fresh()->is_featured)->toBeTrue();
});

<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;

function smiRestaurant(string $sub = 'marcos'): Restaurant
{
    return Restaurant::create([
        'name' => "R-{$sub}",
        'subdomain' => $sub,
        'email' => "hello@{$sub}.test",
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

function smiMember(Restaurant $r, string $role): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id, ['role' => $role]);

    return $u;
}

function smiCategory(Restaurant $r): MenuCategory
{
    return MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas-'.uniqid(),
        'position' => 0,
        'is_active' => true,
    ]);
}

function smiItem(MenuCategory $c, array $attrs = []): MenuItem
{
    return MenuItem::withoutTenantScope()->create(array_merge([
        'restaurant_id' => $c->restaurant_id,
        'menu_category_id' => $c->id,
        'name' => 'Margherita',
        'slug' => 'margherita-'.uniqid(),
        'price_cents' => 1399,
        'is_available' => true,
        'position' => 0,
    ], $attrs));
}

function smiUrl(Restaurant $r, string $path = ''): string
{
    return "http://{$r->subdomain}.plateful.test/admin/menu{$path}";
}

test('admin can create a menu item via storefront', function () {
    $r = smiRestaurant();
    $admin = smiMember($r, 'admin');
    $cat = smiCategory($r);

    $this->actingAs($admin)
        ->post(smiUrl($r, '/items'), [
            'name' => 'Margherita',
            'menu_category_id' => $cat->id,
            'price' => '12.99',
            'is_available' => true,
        ])
        ->assertRedirect();

    $item = MenuItem::withoutTenantScope()->where('restaurant_id', $r->id)->first();
    expect($item)->not->toBeNull()
        ->and($item->price_cents)->toBe(1299)
        ->and($item->name)->toBe('Margherita');
});

test('admin can update a menu item via storefront', function () {
    $r = smiRestaurant();
    $admin = smiMember($r, 'admin');
    $cat = smiCategory($r);
    $item = smiItem($cat);

    $this->actingAs($admin)
        ->put(smiUrl($r, "/items/{$item->id}"), [
            'name' => 'Margherita Deluxe',
            'menu_category_id' => $cat->id,
            'price' => '15.50',
            'is_available' => false,
        ])
        ->assertRedirect();

    $fresh = $item->fresh();
    expect($fresh->name)->toBe('Margherita Deluxe')
        ->and($fresh->price_cents)->toBe(1550)
        ->and($fresh->is_available)->toBeFalse();
});

test('admin can delete a menu item via storefront and order history is preserved', function () {
    $r = smiRestaurant();
    $admin = smiMember($r, 'admin');
    $cat = smiCategory($r);
    $item = smiItem($cat, ['name' => 'Margherita', 'price_cents' => 1399]);

    $customer = User::factory()->create();
    $order = Order::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'user_id' => $customer->id,
        'number' => 'ORD-1',
        'status' => 'pending',
        'type' => 'pickup',
        'subtotal_cents' => 1399,
        'tax_cents' => 0,
        'tip_cents' => 0,
        'delivery_fee_cents' => 0,
        'application_fee_cents' => 0,
        'total_cents' => 1399,
    ]);
    $oi = OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $item->id,
        'name' => $item->name,
        'unit_price_cents' => $item->price_cents,
        'quantity' => 1,
        'subtotal_cents' => $item->price_cents,
    ]);

    $this->actingAs($admin)
        ->delete(smiUrl($r, "/items/{$item->id}"))
        ->assertRedirect();

    $oi->refresh();
    expect(MenuItem::withoutTenantScope()->find($item->id))->toBeNull()
        ->and($oi->menu_item_id)->toBeNull()
        ->and($oi->name)->toBe('Margherita');
});

test('super admin can edit any restaurant menu item', function () {
    $r = smiRestaurant();
    $super = User::factory()->superAdmin()->create();
    $cat = smiCategory($r);
    $item = smiItem($cat);

    $this->actingAs($super)
        ->put(smiUrl($r, "/items/{$item->id}"), [
            'name' => 'Renamed by super',
            'menu_category_id' => $cat->id,
            'price' => '9.99',
            'is_available' => true,
        ])
        ->assertRedirect();

    expect($item->fresh()->name)->toBe('Renamed by super');
});

test('staff cannot create, update, or delete menu items', function () {
    $r = smiRestaurant();
    $staff = smiMember($r, 'staff');
    $cat = smiCategory($r);
    $item = smiItem($cat);

    $this->actingAs($staff)
        ->post(smiUrl($r, '/items'), [
            'name' => 'X', 'menu_category_id' => $cat->id, 'price' => '1.00', 'is_available' => true,
        ])
        ->assertForbidden();

    $this->actingAs($staff)
        ->put(smiUrl($r, "/items/{$item->id}"), [
            'name' => 'X', 'menu_category_id' => $cat->id, 'price' => '1.00', 'is_available' => true,
        ])
        ->assertForbidden();

    $this->actingAs($staff)
        ->delete(smiUrl($r, "/items/{$item->id}"))
        ->assertForbidden();

    expect($item->fresh()->name)->toBe('Margherita');
});

test('admin of one restaurant cannot edit items of another', function () {
    $r1 = smiRestaurant('one');
    $r2 = smiRestaurant('two');
    $adminOfR1 = smiMember($r1, 'admin');
    $cat2 = smiCategory($r2);
    $item2 = smiItem($cat2);

    // PUT against r2's host but as r1's admin → policy denies.
    $this->actingAs($adminOfR1)
        ->put(smiUrl($r2, "/items/{$item2->id}"), [
            'name' => 'Hijacked',
            'menu_category_id' => $cat2->id,
            'price' => '1.00',
            'is_available' => true,
        ])
        ->assertForbidden();

    expect($item2->fresh()->name)->not->toBe('Hijacked');
});

test('unauthenticated user cannot edit menu items', function () {
    $r = smiRestaurant();
    $cat = smiCategory($r);
    $item = smiItem($cat);

    $this->post(smiUrl($r, '/items'), [
        'name' => 'X', 'menu_category_id' => $cat->id, 'price' => '1.00', 'is_available' => true,
    ])->assertRedirect();

    $this->put(smiUrl($r, "/items/{$item->id}"), [
        'name' => 'X', 'menu_category_id' => $cat->id, 'price' => '1.00', 'is_available' => true,
    ])->assertRedirect();

    $this->delete(smiUrl($r, "/items/{$item->id}"))->assertRedirect();

    expect(MenuItem::withoutTenantScope()->find($item->id))->not->toBeNull();
});

test('home renders canEditMenu and editor payload for admin but not for customer', function () {
    config(['platform.primary_domain' => 'plateful.test']);

    $r = smiRestaurant();
    $admin = smiMember($r, 'admin');
    smiCategory($r);

    $this->actingAs($admin)
        ->get("http://{$r->subdomain}.plateful.test/")
        ->assertInertia(fn ($page) => $page
            ->where('auth.canEditMenu', true)
            ->has('editor.categories')
            ->has('editor.templates')
        );

    auth()->logout();
    $customer = User::factory()->create();
    $this->actingAs($customer)
        ->get("http://{$r->subdomain}.plateful.test/")
        ->assertInertia(fn ($page) => $page
            ->where('auth.canEditMenu', false)
            ->where('editor', null)
        );
});

test('home shows unavailable items to admin but hides them from customers', function () {
    config(['platform.primary_domain' => 'plateful.test']);

    $r = smiRestaurant();
    $admin = smiMember($r, 'admin');
    $cat = smiCategory($r);
    smiItem($cat, ['name' => 'Available', 'is_available' => true]);
    smiItem($cat, ['name' => 'Hidden', 'is_available' => false, 'slug' => 'hidden-'.uniqid()]);

    $this->actingAs($admin)
        ->get("http://{$r->subdomain}.plateful.test/")
        ->assertInertia(fn ($page) => $page->has('categories.0.items', 2));

    auth()->logout();

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertInertia(fn ($page) => $page
            ->has('categories.0.items', 1)
            ->where('categories.0.items.0.name', 'Available')
        );
});

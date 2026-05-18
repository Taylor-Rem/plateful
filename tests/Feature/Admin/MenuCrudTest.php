<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\MenuItemModifier;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Models\User;

const MENU_ADMIN_BASE = 'http://admin.plateful.test';

function menuRestaurant(string $sub): Restaurant
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

function attachAdmin(Restaurant $r): User
{
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($r->id);

    return $admin;
}

function makeCategory(Restaurant $r, array $attrs = []): MenuCategory
{
    return MenuCategory::withoutTenantScope()->create(array_merge([
        'restaurant_id' => $r->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas',
        'position' => 0,
        'is_active' => true,
    ], $attrs));
}

function makeItem(MenuCategory $c, array $attrs = []): MenuItem
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

test('admin with access can create a category', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);

    $response = $this->actingAs($admin)
        ->post(MENU_ADMIN_BASE.'/marcos/menu/categories', [
            'name' => 'Pizzas',
        ]);

    $response->assertRedirect();
    expect(MenuCategory::withoutTenantScope()->where('restaurant_id', $r->id)->count())->toBe(1);
});

test('admin without access cannot create category', function () {
    $r = menuRestaurant('marcos');
    $other = User::factory()->admin()->create();

    $response = $this->actingAs($other)
        ->post(MENU_ADMIN_BASE.'/marcos/menu/categories', ['name' => 'Pizzas']);

    $response->assertForbidden();
});

test('category slug auto-generated when omitted', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);

    $this->actingAs($admin)
        ->post(MENU_ADMIN_BASE.'/marcos/menu/categories', ['name' => 'Cold Drinks'])
        ->assertRedirect();

    $cat = MenuCategory::withoutTenantScope()->where('restaurant_id', $r->id)->first();
    expect($cat->slug)->toBe('cold-drinks');
});

test('duplicate category slug in same restaurant fails but allowed across restaurants', function () {
    $a = menuRestaurant('alpha');
    $b = menuRestaurant('beta');
    $adminA = attachAdmin($a);
    $adminB = attachAdmin($b);

    makeCategory($a, ['name' => 'Pizzas', 'slug' => 'pizzas']);

    $this->actingAs($adminA)
        ->post(MENU_ADMIN_BASE.'/alpha/menu/categories', ['name' => 'Pizzas', 'slug' => 'pizzas'])
        ->assertSessionHasErrors('slug');

    $this->actingAs($adminB)
        ->post(MENU_ADMIN_BASE.'/beta/menu/categories', ['name' => 'Pizzas', 'slug' => 'pizzas'])
        ->assertRedirect();

    expect(MenuCategory::withoutTenantScope()->where('restaurant_id', $b->id)->where('slug', 'pizzas')->exists())->toBeTrue();
});

test('renaming a category persists', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);
    $cat = makeCategory($r);

    $this->actingAs($admin)
        ->put(MENU_ADMIN_BASE."/marcos/menu/categories/{$cat->id}", ['name' => 'Specials'])
        ->assertRedirect();

    expect($cat->fresh()->name)->toBe('Specials');
});

test('empty category can be deleted', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);
    $cat = makeCategory($r);

    $this->actingAs($admin)
        ->delete(MENU_ADMIN_BASE."/marcos/menu/categories/{$cat->id}")
        ->assertRedirect();

    expect(MenuCategory::withoutTenantScope()->find($cat->id))->toBeNull();
});

test('category with items returns 422 and is preserved', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);
    $cat = makeCategory($r);
    makeItem($cat);

    $this->actingAs($admin)
        ->delete(MENU_ADMIN_BASE."/marcos/menu/categories/{$cat->id}", [], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(MenuCategory::withoutTenantScope()->find($cat->id))->not->toBeNull();
});

test('reorder categories updates positions', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);
    $a = makeCategory($r, ['name' => 'A', 'slug' => 'a', 'position' => 0]);
    $b = makeCategory($r, ['name' => 'B', 'slug' => 'b', 'position' => 1]);
    $c = makeCategory($r, ['name' => 'C', 'slug' => 'c', 'position' => 2]);

    $this->actingAs($admin)
        ->post(MENU_ADMIN_BASE.'/marcos/menu/categories/reorder', [
            'ids' => [$c->id, $a->id, $b->id],
        ])
        ->assertNoContent();

    expect($c->fresh()->position)->toBe(0)
        ->and($a->fresh()->position)->toBe(1)
        ->and($b->fresh()->position)->toBe(2);
});

test('admin can create item with modifiers and price in dollars converts to cents', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);
    $cat = makeCategory($r);

    $this->actingAs($admin)
        ->post(MENU_ADMIN_BASE.'/marcos/menu/items', [
            'name' => 'Margherita',
            'menu_category_id' => $cat->id,
            'price' => '12.99',
            'is_available' => true,
            'modifiers' => [
                ['name' => 'Small', 'group_label' => 'Size', 'price_delta' => '-1.50', 'is_default' => false],
                ['name' => 'Large', 'group_label' => 'Size', 'price_delta' => '2.00', 'is_default' => true],
            ],
        ])
        ->assertRedirect();

    $item = MenuItem::withoutTenantScope()->where('restaurant_id', $r->id)->first();
    expect($item)->not->toBeNull()
        ->and($item->price_cents)->toBe(1299)
        ->and($item->modifiers()->count())->toBe(2);

    $mods = $item->modifiers()->orderBy('position')->get();
    expect($mods[0]->price_delta_cents)->toBe(-150)
        ->and($mods[1]->price_delta_cents)->toBe(200);
});

test('editing an item replaces modifiers', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);
    $cat = makeCategory($r);
    $item = makeItem($cat);
    $oldMod = MenuItemModifier::create([
        'menu_item_id' => $item->id,
        'name' => 'OldMod',
        'price_delta_cents' => 0,
        'position' => 0,
    ]);

    $this->actingAs($admin)
        ->put(MENU_ADMIN_BASE."/marcos/menu/items/{$item->id}", [
            'name' => 'New Name',
            'menu_category_id' => $cat->id,
            'price' => '15.00',
            'is_available' => true,
            'modifiers' => [
                ['name' => 'NewMod', 'price_delta' => '1.00', 'is_default' => false],
            ],
        ])
        ->assertRedirect();

    expect(MenuItemModifier::find($oldMod->id))->toBeNull()
        ->and($item->fresh()->modifiers()->count())->toBe(1)
        ->and($item->fresh()->modifiers()->first()->name)->toBe('NewMod')
        ->and($item->fresh()->name)->toBe('New Name');
});

test('toggling item unavailable hides it from storefront', function () {
    config(['platform.primary_domain' => 'plateful.test']);

    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);
    $cat = makeCategory($r);
    $item = makeItem($cat, ['name' => 'Margherita', 'is_available' => true]);

    $this->get('http://marcos.plateful.test/')
        ->assertInertia(fn ($page) => $page->where('categories.0.items.0.name', 'Margherita'));

    $this->actingAs($admin)
        ->put(MENU_ADMIN_BASE."/marcos/menu/items/{$item->id}", [
            'name' => 'Margherita',
            'menu_category_id' => $cat->id,
            'price' => '13.99',
            'is_available' => false,
        ])
        ->assertRedirect();

    $this->get('http://marcos.plateful.test/')
        ->assertInertia(fn ($page) => $page->where('categories', []));
});

test('deleting an item preserves order history snapshot', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);
    $cat = makeCategory($r);
    $item = makeItem($cat, ['name' => 'Margherita', 'price_cents' => 1399]);

    $customer = User::factory()->create(['restaurant_id' => $r->id]);

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

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'menu_item_id' => $item->id,
        'name' => $item->name,
        'unit_price_cents' => $item->price_cents,
        'quantity' => 1,
        'subtotal_cents' => $item->price_cents,
    ]);

    $this->actingAs($admin)
        ->delete(MENU_ADMIN_BASE."/marcos/menu/items/{$item->id}")
        ->assertRedirect();

    $orderItem->refresh();
    expect(MenuItem::withoutTenantScope()->find($item->id))->toBeNull()
        ->and($orderItem->menu_item_id)->toBeNull()
        ->and($orderItem->name)->toBe('Margherita')
        ->and($orderItem->unit_price_cents)->toBe(1399);
});

test('item reorder updates positions within a category', function () {
    $r = menuRestaurant('marcos');
    $admin = attachAdmin($r);
    $cat = makeCategory($r);
    $a = makeItem($cat, ['name' => 'A', 'position' => 0]);
    $b = makeItem($cat, ['name' => 'B', 'position' => 1]);
    $c = makeItem($cat, ['name' => 'C', 'position' => 2]);

    $this->actingAs($admin)
        ->post(MENU_ADMIN_BASE.'/marcos/menu/items/reorder', [
            'category_id' => $cat->id,
            'ids' => [$c->id, $a->id, $b->id],
        ])
        ->assertNoContent();

    expect($c->fresh()->position)->toBe(0)
        ->and($a->fresh()->position)->toBe(1)
        ->and($b->fresh()->position)->toBe(2);
});

test('super admin cannot act on item belonging to another restaurant', function () {
    $marcos = menuRestaurant('marcos');
    $other = menuRestaurant('other');
    $superAdmin = User::factory()->superAdmin()->create();

    $otherCat = makeCategory($other);
    $otherItem = makeItem($otherCat);

    $this->actingAs($superAdmin)
        ->get(MENU_ADMIN_BASE."/marcos/menu/items/{$otherItem->id}/edit")
        ->assertNotFound();
});

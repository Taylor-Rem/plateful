<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
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

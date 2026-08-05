<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;

function categoryRestaurant(string $sub = 'marcos'): Restaurant
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

function categoryAdmin(Restaurant $r, string $role = 'admin'): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id, ['role' => $role]);

    return $u;
}

function categoryUrl(Restaurant $r, string $path = ''): string
{
    return "http://{$r->subdomain}.plateful.test/admin/menu/categories{$path}";
}

function categoryMake(Restaurant $r, string $name, int $position = 0): MenuCategory
{
    return MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => $name,
        'slug' => str($name)->slug()->toString(),
        'position' => $position,
        'is_active' => true,
    ]);
}

test('guest cannot create a category', function () {
    $r = categoryRestaurant();

    $this->post(categoryUrl($r), ['name' => 'Apps'])->assertRedirect();

    expect(MenuCategory::withoutTenantScope()->count())->toBe(0);
});

test('staff cannot create a category', function () {
    $r = categoryRestaurant();
    $staff = categoryAdmin($r, 'staff');

    $this->actingAs($staff)
        ->post(categoryUrl($r), ['name' => 'Apps'])
        ->assertForbidden();

    expect(MenuCategory::withoutTenantScope()->count())->toBe(0);
});

test('admin can create a category from the storefront', function () {
    $r = categoryRestaurant();
    $admin = categoryAdmin($r);
    categoryMake($r, 'Existing', 0);

    $this->actingAs($admin)
        ->post(categoryUrl($r), [
            'name' => 'Desserts',
            'description' => 'Sweet endings',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $category = MenuCategory::withoutTenantScope()
        ->where('name', 'Desserts')
        ->firstOrFail();

    expect($category->slug)->toBe('desserts')
        ->and($category->description)->toBe('Sweet endings')
        ->and($category->position)->toBe(1)
        ->and($category->is_active)->toBeTrue();
});

test('admin can rename a category and set its description', function () {
    $r = categoryRestaurant();
    $admin = categoryAdmin($r);
    $category = categoryMake($r, 'Apps');

    $this->actingAs($admin)
        ->put(categoryUrl($r, "/{$category->id}"), [
            'name' => 'Appetizers',
            'description' => 'To start',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = $category->fresh();
    expect($fresh->name)->toBe('Appetizers')
        ->and($fresh->description)->toBe('To start');
});

test('deleting a category also deletes its items', function () {
    $r = categoryRestaurant();
    $admin = categoryAdmin($r);
    $category = categoryMake($r, 'Apps');
    $item = MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $category->id,
        'name' => 'Wings',
        'slug' => 'wings',
        'price_cents' => 999,
        'is_available' => true,
        'position' => 0,
    ]);

    $this->actingAs($admin)
        ->delete(categoryUrl($r, "/{$category->id}"))
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Category and 1 item deleted.');

    expect(MenuCategory::withoutTenantScope()->find($category->id))->toBeNull()
        ->and(MenuItem::withoutTenantScope()->find($item->id))->toBeNull();
});

test('admin can delete an empty category', function () {
    $r = categoryRestaurant();
    $admin = categoryAdmin($r);
    $category = categoryMake($r, 'Apps');

    $this->actingAs($admin)
        ->delete(categoryUrl($r, "/{$category->id}"))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(MenuCategory::withoutTenantScope()->count())->toBe(0);
});

test('admin can reorder categories', function () {
    $r = categoryRestaurant();
    $admin = categoryAdmin($r);
    $a = categoryMake($r, 'Apps', 0);
    $b = categoryMake($r, 'Mains', 1);
    $c = categoryMake($r, 'Desserts', 2);

    $this->actingAs($admin)
        ->post(categoryUrl($r, '/reorder'), ['ids' => [$c->id, $a->id, $b->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $ordered = MenuCategory::withoutTenantScope()
        ->where('restaurant_id', $r->id)
        ->orderBy('position')
        ->pluck('id')
        ->all();

    expect($ordered)->toBe([$c->id, $a->id, $b->id]);
});

test('cross-tenant category access is blocked', function () {
    $r1 = categoryRestaurant('marcos');
    $r2 = categoryRestaurant('luigis');
    $r1Admin = categoryAdmin($r1);
    $r2Category = categoryMake($r2, 'Their Apps');

    $this->actingAs($r1Admin)
        ->put(categoryUrl($r1, "/{$r2Category->id}"), ['name' => 'hacked'])
        ->assertNotFound();

    expect($r2Category->fresh()->name)->toBe('Their Apps');
});

test('a reorder cannot include another restaurant\'s categories', function () {
    $r1 = categoryRestaurant('marcos');
    $r2 = categoryRestaurant('luigis');
    $r1Admin = categoryAdmin($r1);
    $mine = categoryMake($r1, 'Mine');
    $theirs = categoryMake($r2, 'Theirs', 5);

    $this->actingAs($r1Admin)
        ->post(categoryUrl($r1, '/reorder'), ['ids' => [$theirs->id, $mine->id]])
        ->assertSessionHasErrors('ids.0');

    expect($theirs->fresh()->position)->toBe(5);
});

test('the storefront menu shows empty categories to admins but not customers', function () {
    $r = categoryRestaurant();
    $admin = categoryAdmin($r);
    categoryMake($r, 'Empty For Now');

    $this->actingAs($admin)
        ->get("http://{$r->subdomain}.plateful.test/menu")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('categories', 1));

    $customer = User::factory()->create();
    $this->actingAs($customer)
        ->get("http://{$r->subdomain}.plateful.test/menu")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('categories', 0));
});

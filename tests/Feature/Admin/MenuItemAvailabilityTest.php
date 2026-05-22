<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;

const AVAIL_ADMIN_BASE = 'http://admin.plateful.test';

function availRestaurant(string $sub = 'availtest'): Restaurant
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

function availMember(Restaurant $r, string $role): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id, ['role' => $role]);

    return $u;
}

function availItem(Restaurant $r, bool $isAvailable = true): MenuItem
{
    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Mains',
        'slug' => 'mains-'.uniqid(),
        'position' => 0,
        'is_active' => true,
    ]);

    return MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'name' => 'Burger',
        'slug' => 'burger-'.uniqid(),
        'price_cents' => 1200,
        'is_available' => $isAvailable,
        'position' => 0,
    ]);
}

test('staff can toggle item availability from in-stock to unavailable', function () {
    $r = availRestaurant();
    $staff = availMember($r, 'staff');
    $item = availItem($r, true);

    $this->actingAs($staff)
        ->post(AVAIL_ADMIN_BASE."/{$r->subdomain}/menu/items/{$item->id}/availability")
        ->assertRedirect();

    expect($item->fresh()->is_available)->toBeFalse();
});

test('staff can toggle item availability back to in-stock', function () {
    $r = availRestaurant();
    $staff = availMember($r, 'staff');
    $item = availItem($r, false);

    $this->actingAs($staff)
        ->post(AVAIL_ADMIN_BASE."/{$r->subdomain}/menu/items/{$item->id}/availability")
        ->assertRedirect();

    expect($item->fresh()->is_available)->toBeTrue();
});

test('admin can toggle item availability', function () {
    $r = availRestaurant();
    $admin = availMember($r, 'admin');
    $item = availItem($r, true);

    $this->actingAs($admin)
        ->post(AVAIL_ADMIN_BASE."/{$r->subdomain}/menu/items/{$item->id}/availability")
        ->assertRedirect();

    expect($item->fresh()->is_available)->toBeFalse();
});

test('user without restaurant membership cannot toggle availability', function () {
    $r = availRestaurant();
    $outsider = User::factory()->admin()->create();
    $item = availItem($r, true);

    $this->actingAs($outsider)
        ->post(AVAIL_ADMIN_BASE."/{$r->subdomain}/menu/items/{$item->id}/availability")
        ->assertForbidden();

    expect($item->fresh()->is_available)->toBeTrue();
});

test('cannot toggle an item from another restaurant', function () {
    $r1 = availRestaurant('one');
    $r2 = availRestaurant('two');
    $admin = availMember($r1, 'admin');
    $item = availItem($r2, true);

    $this->actingAs($admin)
        ->post(AVAIL_ADMIN_BASE."/{$r1->subdomain}/menu/items/{$item->id}/availability")
        ->assertNotFound();

    expect($item->fresh()->is_available)->toBeTrue();
});

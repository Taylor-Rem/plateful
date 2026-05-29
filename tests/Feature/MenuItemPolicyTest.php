<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Policies\MenuItemPolicy;

function policyRestaurant(string $sub = 'r'): Restaurant
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

function policyMember(Restaurant $r, string $role): User
{
    $u = User::factory()->admin()->create();
    $u->restaurants()->attach($r->id, ['role' => $role]);

    return $u;
}

function policyItem(Restaurant $r): MenuItem
{
    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'C',
        'slug' => 'c-'.uniqid(),
        'position' => 0,
        'is_active' => true,
    ]);

    return MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'name' => 'I',
        'slug' => 'i-'.uniqid(),
        'price_cents' => 100,
        'is_available' => true,
        'position' => 0,
    ]);
}

test('restaurant admin can create/update/delete items in their restaurant', function () {
    $r = policyRestaurant();
    $admin = policyMember($r, 'admin');
    $item = policyItem($r);
    $policy = new MenuItemPolicy;

    expect($policy->create($admin, $r))->toBeTrue()
        ->and($policy->update($admin, $item))->toBeTrue()
        ->and($policy->delete($admin, $item))->toBeTrue();
});

test('staff cannot create/update/delete items', function () {
    $r = policyRestaurant();
    $staff = policyMember($r, 'staff');
    $item = policyItem($r);
    $policy = new MenuItemPolicy;

    expect($policy->create($staff, $r))->toBeFalse()
        ->and($policy->update($staff, $item))->toBeFalse()
        ->and($policy->delete($staff, $item))->toBeFalse();
});

test('super admin can manage items in any restaurant', function () {
    $r = policyRestaurant();
    $super = User::factory()->superAdmin()->create();
    $item = policyItem($r);
    $policy = new MenuItemPolicy;

    expect($policy->create($super, $r))->toBeTrue()
        ->and($policy->update($super, $item))->toBeTrue()
        ->and($policy->delete($super, $item))->toBeTrue();
});

test('admin of restaurant A cannot manage items in restaurant B', function () {
    $a = policyRestaurant('a');
    $b = policyRestaurant('b');
    $adminA = policyMember($a, 'admin');
    $itemB = policyItem($b);
    $policy = new MenuItemPolicy;

    expect($policy->create($adminA, $b))->toBeFalse()
        ->and($policy->update($adminA, $itemB))->toBeFalse()
        ->and($policy->delete($adminA, $itemB))->toBeFalse();
});

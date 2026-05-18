<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createRestaurantWithItem(string $subdomain, string $itemName): MenuItem
{
    $restaurant = Restaurant::create([
        'name' => $subdomain,
        'subdomain' => $subdomain,
        'email' => "hello@{$subdomain}.test",
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);

    $category = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Cat',
        'slug' => 'cat-'.$subdomain,
    ]);

    return MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $restaurant->id,
        'menu_category_id' => $category->id,
        'name' => $itemName,
        'slug' => 'slug-'.$itemName,
        'price_cents' => 1000,
    ]);
}

test('tenant scope filters menu items to current tenant', function () {
    $itemA = createRestaurantWithItem('aaa', 'PizzaA');
    $itemB = createRestaurantWithItem('bbb', 'PizzaB');

    $tenantA = Restaurant::query()->where('subdomain', 'aaa')->first();
    app(CurrentTenant::class)->set($tenantA);

    $names = MenuItem::query()->pluck('name')->all();
    expect($names)->toEqual(['PizzaA']);

    app(CurrentTenant::class)->clear();

    $allNames = MenuItem::query()->pluck('name')->sort()->values()->all();
    expect($allNames)->toEqual(['PizzaA', 'PizzaB']);
});

test('withoutTenantScope returns all items', function () {
    createRestaurantWithItem('aaa', 'PizzaA');
    createRestaurantWithItem('bbb', 'PizzaB');

    $tenantA = Restaurant::query()->where('subdomain', 'aaa')->first();
    app(CurrentTenant::class)->set($tenantA);

    $all = MenuItem::withoutTenantScope()->get();
    expect($all)->toHaveCount(2);
});

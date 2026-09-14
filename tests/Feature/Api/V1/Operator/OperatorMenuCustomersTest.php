<?php

use App\Enums\ApiKeyScope;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantHour;

require_once __DIR__.'/../ApiHelpers.php';
require_once __DIR__.'/../../../Storefront/CartTestHelpers.php';
require_once __DIR__.'/../../../Admin/CustomerTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('the operator menu includes hidden categories and sold-out items', function () {
    ['restaurant' => $restaurant, 'simple' => $soda] = cartFixture();
    $soda->update(['is_available' => false]);
    $hidden = MenuCategory::create(['restaurant_id' => $restaurant->id, 'name' => 'Secret', 'slug' => 'secret', 'position' => 5, 'is_active' => false]);
    MenuItem::create(['restaurant_id' => $restaurant->id, 'menu_category_id' => $hidden->id, 'name' => 'Off menu', 'slug' => 'off', 'price_cents' => 100, 'is_available' => true, 'position' => 0]);

    $this->withToken(apiKeyFor($restaurant))->getJson(operatorUrl($restaurant, 'menu'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.items.1.name', 'Soda')
        ->assertJsonPath('data.0.items.1.isAvailable', false)
        ->assertJsonPath('data.1.name', 'Secret');

    // The customer API still hides both.
    $this->getJson(API_BASE."/restaurants/{$restaurant->subdomain}/menu")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonCount(1, 'data.0.items');
});

test('86ing an item hides it from customers until it is back', function () {
    ['restaurant' => $restaurant, 'simple' => $soda] = cartFixture();

    $this->withToken(apiKeyFor($restaurant));

    $this->patchJson(operatorUrl($restaurant, "menu-items/{$soda->id}/availability"), ['is_available' => false])
        ->assertOk()
        ->assertJsonPath('data.id', $soda->id)
        ->assertJsonPath('data.isAvailable', false);

    expect($soda->fresh()->is_available)->toBeFalse();

    $this->patchJson(operatorUrl($restaurant, "menu-items/{$soda->id}/availability"), ['is_available' => true])
        ->assertOk()
        ->assertJsonPath('data.isAvailable', true);

    $this->patchJson(operatorUrl($restaurant, "menu-items/{$soda->id}/availability"), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['is_available']);
});

test('a menu item from another restaurant is a 404 here', function () {
    ['restaurant' => $restaurant] = cartFixture('marcos');
    ['simple' => $foreign] = cartFixture('luigis');

    $this->withToken(apiKeyFor($restaurant))
        ->patchJson(operatorUrl($restaurant, "menu-items/{$foreign->id}/availability"), ['is_available' => false])
        ->assertNotFound();

    expect($foreign->fresh()->is_available)->toBeTrue();
});

test('customers list with search, paging and no deleted accounts', function () {
    $restaurant = Restaurant::factory()->create();
    $ada = customerUser('Ada Diner', 'ada@example.test');
    $bob = customerUser('Bob Guest', 'bob@example.test');
    $gone = customerUser('Gone Person', 'gone@example.test');
    customerPivot($restaurant, $ada, ['total_orders' => 9, 'total_spent_cents' => 9000, 'last_ordered_at' => now()->subDay()]);
    customerPivot($restaurant, $bob, ['last_ordered_at' => now()->subDays(40)]);
    customerPivot($restaurant, $gone);
    $gone->delete();
    customerPivot(Restaurant::factory()->create(), customerUser('Elsewhere', 'else@example.test'));

    $this->withToken(apiKeyFor($restaurant, [ApiKeyScope::CustomersRead]));

    $this->getJson(operatorUrl($restaurant, 'customers'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Ada Diner')
        ->assertJsonPath('data.0.totalOrders', 9)
        ->assertJsonPath('meta.total', 2);

    $this->getJson(operatorUrl($restaurant, 'customers?search=bob'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.email', 'bob@example.test');

    $this->getJson(operatorUrl($restaurant, 'customers?ordered=30'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Ada Diner');

    $this->getJson(operatorUrl($restaurant, 'customers?sort=name&dir=asc&per_page=1'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Ada Diner')
        ->assertJsonPath('meta.lastPage', 2);
});

test('the restaurant profile carries hours', function () {
    $restaurant = Restaurant::factory()->create();
    RestaurantHour::create(['restaurant_id' => $restaurant->id, 'day_of_week' => 1, 'opens_at' => '11:00', 'closes_at' => '21:00', 'position' => 0]);

    $this->withToken(apiKeyFor($restaurant, [ApiKeyScope::RestaurantsRead]))
        ->getJson(operatorUrl($restaurant))
        ->assertOk()
        ->assertJsonPath('data.subdomain', $restaurant->subdomain)
        ->assertJsonPath('data.hoursByDay.1.0.opensAt', '11:00');
});

<?php

use App\Enums\RestaurantRole;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Pest\Browser\Playwright\Playwright;

// The admin console is domain-routed; this makes the plugin's in-process
// server present every request with the admin host so those routes match.
beforeEach(function () {
    Playwright::setHost('admin.plateful.test');
});

function mobileBrowserRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'Mobile Pizzeria',
        'subdomain' => 'mobilejoint',
        'email' => 'hello@mobilejoint.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

/**
 * The page must not overflow the phone viewport horizontally — a wider
 * scrollWidth is exactly the "have to pan sideways" mobile bug.
 */
function assertNoHorizontalOverflow(mixed $page): void
{
    $overflow = $page->script(
        'document.documentElement.scrollWidth - document.documentElement.clientWidth',
    );

    expect($overflow)->toBeLessThanOrEqual(0);
}

test('tenant admin pages fit a phone viewport', function (string $path) {
    $admin = User::factory()->admin()->create();
    $restaurant = mobileBrowserRestaurant();
    $admin->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Admin->value]);
    Order::factory()->count(3)->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($admin);

    $page = visit("/mobilejoint/{$path}")->on()->iPhone15Pro();

    $page->assertNoJavaScriptErrors();
    assertNoHorizontalOverflow($page);
})->with([
    'dashboard',
    'orders',
    'kitchen',
    'menu',
    'hours',
    'members',
    'payouts',
    'settings',
    'settings/pos',
    'settings/delivery',
    'menu/templates',
    'onboarding',
]);

test('super admin pages fit a phone viewport', function (string $path) {
    $superAdmin = User::factory()->superAdmin()->create();
    $restaurant = mobileBrowserRestaurant();
    Order::factory()->count(3)->create(['restaurant_id' => $restaurant->id]);

    $this->actingAs($superAdmin);

    $page = visit($path)->on()->iPhone15Pro();

    $page->assertNoJavaScriptErrors();
    assertNoHorizontalOverflow($page);
})->with([
    '/super/restaurants',
    '/super/restaurants/mobilejoint',
    '/super/users',
    '/super/admins',
    '/super/earnings',
    '/security',
]);

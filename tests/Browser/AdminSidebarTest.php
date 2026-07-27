<?php

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;
use Pest\Browser\Playwright\Playwright;

// The admin console is domain-routed; this makes the plugin's in-process
// server present every request with the admin host so those routes match.
beforeEach(function () {
    Playwright::setHost('admin.plateful.test');
});

function sidebarBrowserRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'Browser Pizzeria',
        'subdomain' => 'browserjoint',
        'email' => 'hello@browserjoint.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

test('restaurant admins see the full grouped sidebar', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = sidebarBrowserRestaurant();
    $admin->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Admin->value]);

    $this->actingAs($admin);

    visit('/browserjoint/dashboard')
        ->assertNoJavaScriptErrors()
        ->assertSee('Operations')
        ->assertSee('Payouts')
        ->assertSee('Team')
        ->assertSee('Settings');
});

test('staff members do not see admin-only sidebar items', function () {
    $staff = User::factory()->create();
    $restaurant = sidebarBrowserRestaurant();
    $staff->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff);

    visit('/browserjoint/dashboard')
        ->assertNoJavaScriptErrors()
        ->assertSee('Kitchen')
        ->assertDontSee('Payouts')
        ->assertDontSee('Team');
});

test('the super admin pages render without javascript errors', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    sidebarBrowserRestaurant();

    $this->actingAs($superAdmin);

    visit('/super/restaurants')
        ->assertNoJavaScriptErrors()
        ->assertSee('Restaurants')
        ->assertSee('Earnings')
        ->assertSee('Admins')
        ->assertSee('Users');
});

test('the users roster renders for super admins', function () {
    $superAdmin = User::factory()->superAdmin()->create(['name' => 'Sam Super']);
    $restaurant = sidebarBrowserRestaurant();

    $customer = User::factory()->create(['name' => 'Cal Customer']);
    $customer->customerRestaurants()->attach($restaurant);

    $this->actingAs($superAdmin);

    visit('/super/users')
        ->assertNoJavaScriptErrors()
        ->assertSee('Sam Super')
        ->assertSee('Cal Customer')
        ->assertSee('Customer');
});

test('the user detail page renders for super admins', function () {
    $superAdmin = User::factory()->superAdmin()->create();
    $target = User::factory()->create(['name' => 'Ozzy Orphan']);

    $this->actingAs($superAdmin);

    visit("/super/users/{$target->id}")
        ->assertNoJavaScriptErrors()
        ->assertSee('Ozzy Orphan')
        ->assertSee('Footprint');
});

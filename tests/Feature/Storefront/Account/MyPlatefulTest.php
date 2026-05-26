<?php

use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function mpR(string $sub): Restaurant
{
    return Restaurant::create([
        'name' => ucfirst($sub), 'subdomain' => $sub, 'email' => "$sub@x.test",
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '10001',
    ]);
}

function mpU(string $email = 'c@c.test'): User
{
    return User::create([
        'name' => 'C', 'email' => $email,
        'password' => Hash::make('password'),
        'is_super_admin' => false,
    ]);
}

test('guests are redirected to login from /account/my-plateful', function () {
    $r = mpR('marcos');

    $resp = $this->get("http://{$r->subdomain}.plateful.test/account/my-plateful");

    $resp->assertRedirect('/login');
});

test('empty state renders when user has no restaurant_customer rows', function () {
    $r = mpR('marcos');
    $u = mpU();

    $resp = $this->actingAs($u)->get("http://{$r->subdomain}.plateful.test/account/my-plateful");

    $resp->assertOk()->assertInertia(fn ($p) => $p
        ->component('Storefront/Account/MyPlateful')
        ->has('restaurants', 0));
});

test('lists every restaurant the user has a pivot row for, across tenants', function () {
    $marcos = mpR('marcos');
    $bobs = mpR('bobs');
    $unrelated = mpR('joes'); // user has no pivot here
    $u = mpU();

    RestaurantCustomer::create([
        'user_id' => $u->id,
        'restaurant_id' => $marcos->id,
        'total_orders' => 3,
        'total_spent_cents' => 4500,
        'first_ordered_at' => now()->subDays(10),
        'last_ordered_at' => now()->subDays(2),
    ]);
    RestaurantCustomer::create([
        'user_id' => $u->id,
        'restaurant_id' => $bobs->id,
        'total_orders' => 1,
        'total_spent_cents' => 1200,
        'first_ordered_at' => now()->subDays(20),
        'last_ordered_at' => now()->subDays(20),
    ]);

    $resp = $this->actingAs($u)->get("http://{$marcos->subdomain}.plateful.test/account/my-plateful");

    $resp->assertOk()->assertInertia(fn ($p) => $p
        ->component('Storefront/Account/MyPlateful')
        ->has('restaurants', 2)
        // marcos has more recent last_ordered_at so it sorts first
        ->where('restaurants.0.subdomain', 'marcos')
        ->where('restaurants.0.totalOrders', 3)
        ->where('restaurants.0.totalSpentCents', 4500)
        ->where('restaurants.1.subdomain', 'bobs')
        ->where('restaurants.1.totalOrders', 1));

    // The unrelated restaurant must not appear.
    $resp->assertInertia(fn ($p) => $p->where('restaurants', function ($list) use ($unrelated) {
        foreach ($list as $entry) {
            if ($entry['id'] === $unrelated->id) {
                return false;
            }
        }

        return true;
    }));
});

test('publicUrl points to the restaurant\'s own subdomain', function () {
    $marcos = mpR('marcos');
    $bobs = mpR('bobs');
    $u = mpU();

    RestaurantCustomer::create([
        'user_id' => $u->id,
        'restaurant_id' => $bobs->id,
    ]);

    $resp = $this->actingAs($u)->get("http://{$marcos->subdomain}.plateful.test/account/my-plateful");

    $resp->assertInertia(fn ($p) => $p
        ->where('restaurants.0.subdomain', 'bobs')
        ->where('restaurants.0.publicUrl', 'http://bobs.plateful.test'));
});

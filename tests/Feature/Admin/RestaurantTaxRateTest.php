<?php

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function taxRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => "Marco's",
        'subdomain' => 'marcos',
        'email' => 'hello@m.test',
        'street' => '1',
        'city' => 'NY',
        'state' => 'NY',
        'postal_code' => '1',
    ]);
}

function taxAdmin(Restaurant $r): User
{
    $u = User::create([
        'restaurant_id' => null,
        'is_super_admin' => false,
        'name' => 'Owner',
        'email' => 'admin@m.test',
        'password' => Hash::make('password'),
        'role' => UserRole::Admin,
        'email_verified_at' => now(),
    ]);
    $u->restaurants()->attach($r->id);

    return $u;
}

test('admin can update tax_rate_percent', function () {
    $r = taxRestaurant();
    $u = taxAdmin($r);

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/settings", [
            'name' => $r->name,
            'tax_rate_percent' => '7.25',
        ])
        ->assertRedirect();

    expect((float) $r->fresh()->tax_rate_percent)->toBe(7.25);
});

test('tax rate above 30 fails validation', function () {
    $r = taxRestaurant();
    $u = taxAdmin($r);

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/settings", [
            'name' => $r->name,
            'tax_rate_percent' => 99,
        ])
        ->assertSessionHasErrors('tax_rate_percent');
});

test('negative tax rate fails validation', function () {
    $r = taxRestaurant();
    $u = taxAdmin($r);

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/settings", [
            'name' => $r->name,
            'tax_rate_percent' => -1,
        ])
        ->assertSessionHasErrors('tax_rate_percent');
});

test('tax rate appears in RestaurantData on storefront response', function () {
    $r = taxRestaurant();
    $r->update(['tax_rate_percent' => 6.5]);

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertInertia(fn ($p) => $p
            ->where('restaurant.taxRatePercent', 6.5)
        );
});

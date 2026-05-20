<?php

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function deliveryFeeRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => "Marco's",
        'subdomain' => 'marcos',
        'email' => 'hello@m.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
}

function deliveryFeeAdmin(Restaurant $r): User
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

test('admin can update delivery_fee_cents via settings', function () {
    $r = deliveryFeeRestaurant();
    $u = deliveryFeeAdmin($r);

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/settings", [
            'name' => $r->name,
            'tax_rate_percent' => '0',
            'delivery_fee' => '4.99',
        ])
        ->assertRedirect();

    expect($r->fresh()->delivery_fee_cents)->toBe(499);
});

test('delivery fee appears on storefront response', function () {
    $r = deliveryFeeRestaurant();
    $r->update(['delivery_fee_cents' => 350]);

    $this->get("http://{$r->subdomain}.plateful.test/")
        ->assertInertia(fn ($p) => $p->where('restaurant.deliveryFeeCents', 350));
});

test('delivery fee above $500 is rejected', function () {
    $r = deliveryFeeRestaurant();
    $u = deliveryFeeAdmin($r);

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/settings", [
            'name' => $r->name,
            'tax_rate_percent' => '0',
            'delivery_fee' => '1000',
        ])
        ->assertSessionHasErrors('delivery_fee');
});

test('negative delivery fee is rejected', function () {
    $r = deliveryFeeRestaurant();
    $u = deliveryFeeAdmin($r);

    $this->actingAs($u)
        ->put("http://admin.plateful.test/{$r->subdomain}/settings", [
            'name' => $r->name,
            'tax_rate_percent' => '0',
            'delivery_fee' => '-1',
        ])
        ->assertSessionHasErrors('delivery_fee');
});

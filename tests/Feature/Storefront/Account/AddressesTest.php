<?php

use App\Enums\UserRole;
use App\Models\Address;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function addrR(string $sub = 'marcos'): Restaurant
{
    return Restaurant::create([
        'name' => ucfirst($sub), 'subdomain' => $sub, 'email' => "$sub@x.test",
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
}

function addrU(Restaurant $r, string $email = 'c@c.test'): User
{
    return User::create([
        'restaurant_id' => $r->id, 'name' => 'C', 'email' => $email,
        'password' => Hash::make('password'),
        'role' => UserRole::Customer, 'is_super_admin' => false,
    ]);
}

test('customer can list addresses', function () {
    $r = addrR();
    $u = addrU($r);
    Address::create([
        'user_id' => $u->id, 'street' => '1 Main', 'city' => 'NY',
        'state' => 'NY', 'postal_code' => '10001', 'country' => 'US',
    ]);

    $resp = $this->actingAs($u)->get("http://{$r->subdomain}.plateful.test/account/addresses");

    $resp->assertOk()->assertInertia(fn ($p) => $p
        ->component('Storefront/Account/Addresses')
        ->has('addresses', 1));
});

test('customer can create an address', function () {
    $r = addrR();
    $u = addrU($r);

    $resp = $this->actingAs($u)->post("http://{$r->subdomain}.plateful.test/account/addresses", [
        'label' => 'Home',
        'street' => '1 Main',
        'city' => 'NY',
        'state' => 'NY',
        'postal_code' => '10001',
        'is_default' => true,
    ]);

    $resp->assertRedirect();
    expect($u->addresses()->count())->toBe(1);
    expect($u->addresses()->first()->is_default)->toBeTrue();
});

test('creating a default address unsets other defaults', function () {
    $r = addrR();
    $u = addrU($r);
    $existing = Address::create([
        'user_id' => $u->id, 'street' => '1 A', 'city' => 'NY',
        'state' => 'NY', 'postal_code' => '10001', 'country' => 'US',
        'is_default' => true,
    ]);

    $this->actingAs($u)->post("http://{$r->subdomain}.plateful.test/account/addresses", [
        'street' => '2 B', 'city' => 'NY', 'state' => 'NY',
        'postal_code' => '10002', 'is_default' => true,
    ]);

    expect($existing->fresh()->is_default)->toBeFalse();
});

test('customer can update their own address', function () {
    $r = addrR();
    $u = addrU($r);
    $a = Address::create([
        'user_id' => $u->id, 'street' => '1 A', 'city' => 'NY',
        'state' => 'NY', 'postal_code' => '10001', 'country' => 'US',
    ]);

    $this->actingAs($u)->patch("http://{$r->subdomain}.plateful.test/account/addresses/{$a->id}", [
        'street' => '99 Z', 'city' => 'LA', 'state' => 'CA',
        'postal_code' => '90001',
    ]);

    expect($a->fresh()->street)->toBe('99 Z');
});

test('cannot edit another users address', function () {
    $r = addrR();
    $u = addrU($r, 'u@u.test');
    $other = addrU($r, 'o@o.test');
    $a = Address::create([
        'user_id' => $other->id, 'street' => '1 A', 'city' => 'NY',
        'state' => 'NY', 'postal_code' => '10001', 'country' => 'US',
    ]);

    $resp = $this->actingAs($u)->patch("http://{$r->subdomain}.plateful.test/account/addresses/{$a->id}", [
        'street' => 'HACK', 'city' => 'X', 'state' => 'X',
        'postal_code' => '00000',
    ]);

    $resp->assertNotFound();
    expect($a->fresh()->street)->toBe('1 A');
});

test('customer can delete their own address', function () {
    $r = addrR();
    $u = addrU($r);
    $a = Address::create([
        'user_id' => $u->id, 'street' => '1 A', 'city' => 'NY',
        'state' => 'NY', 'postal_code' => '10001', 'country' => 'US',
    ]);

    $this->actingAs($u)->delete("http://{$r->subdomain}.plateful.test/account/addresses/{$a->id}");

    expect(Address::find($a->id))->toBeNull();
});

test('cannot delete another users address', function () {
    $r = addrR();
    $u = addrU($r, 'u@u.test');
    $other = addrU($r, 'o@o.test');
    $a = Address::create([
        'user_id' => $other->id, 'street' => '1 A', 'city' => 'NY',
        'state' => 'NY', 'postal_code' => '10001', 'country' => 'US',
    ]);

    $resp = $this->actingAs($u)->delete("http://{$r->subdomain}.plateful.test/account/addresses/{$a->id}");

    $resp->assertNotFound();
    expect(Address::find($a->id))->not->toBeNull();
});

test('validation rejects empty required fields', function () {
    $r = addrR();
    $u = addrU($r);

    $resp = $this->actingAs($u)->post("http://{$r->subdomain}.plateful.test/account/addresses", [
        'street' => '', 'city' => '', 'state' => '', 'postal_code' => '',
    ]);

    $resp->assertSessionHasErrors(['street', 'city', 'state', 'postal_code']);
});

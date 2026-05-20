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

function profileR(string $sub = 'marcos'): Restaurant
{
    return Restaurant::create([
        'name' => ucfirst($sub), 'subdomain' => $sub, 'email' => "$sub@x.test",
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
}

function profileU(Restaurant $r, string $email = 'c@c.test'): User
{
    return User::create([
        'restaurant_id' => $r->id, 'name' => 'C', 'email' => $email,
        'password' => Hash::make('password'),
        'role' => UserRole::Customer, 'is_super_admin' => false,
    ]);
}

test('customer can update name, email, and phone', function () {
    $r = profileR();
    $u = profileU($r);

    $this->actingAs($u)->patch("http://{$r->subdomain}.plateful.test/account/profile", [
        'name' => 'New Name',
        'email' => 'new@new.test',
        'phone' => '555-1212',
    ]);

    $u->refresh();
    expect($u->name)->toBe('New Name');
    expect($u->email)->toBe('new@new.test');
    expect($u->phone)->toBe('555-1212');
});

test('email change to another customer in the same tenant fails', function () {
    $r = profileR();
    $u = profileU($r, 'a@a.test');
    profileU($r, 'b@b.test');

    $resp = $this->actingAs($u)->patch("http://{$r->subdomain}.plateful.test/account/profile", [
        'name' => 'A', 'email' => 'b@b.test',
    ]);

    $resp->assertSessionHasErrors('email');
});

test('same email across different tenants is allowed', function () {
    $marcos = profileR('marcos');
    $bobs = profileR('bobs');
    $alice = profileU($marcos, 'alice@a.test');
    profileU($bobs, 'shared@x.test');

    $resp = $this->actingAs($alice)->patch("http://{$marcos->subdomain}.plateful.test/account/profile", [
        'name' => 'Alice', 'email' => 'shared@x.test',
    ]);

    $resp->assertSessionDoesntHaveErrors('email');
    expect($alice->fresh()->email)->toBe('shared@x.test');
});

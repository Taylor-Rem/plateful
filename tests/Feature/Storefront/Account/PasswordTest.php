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

function pwR(): Restaurant
{
    return Restaurant::create([
        'name' => 'M', 'subdomain' => 'marcos', 'email' => 'm@m.test',
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
}

function pwU(Restaurant $r, string $password = 'password'): User
{
    return User::create([
        'restaurant_id' => $r->id, 'name' => 'C', 'email' => 'c@c.test',
        'password' => Hash::make($password),
        'role' => UserRole::Customer, 'is_super_admin' => false,
    ]);
}

test('customer can change password with correct current password', function () {
    $r = pwR();
    $u = pwU($r);

    $this->actingAs($u)->patch("http://{$r->subdomain}.plateful.test/account/password", [
        'current_password' => 'password',
        'password' => 'NewSecret9!Pass',
        'password_confirmation' => 'NewSecret9!Pass',
    ])->assertSessionDoesntHaveErrors();

    expect(Hash::check('NewSecret9!Pass', $u->fresh()->password))->toBeTrue();
});

test('wrong current password fails', function () {
    $r = pwR();
    $u = pwU($r);

    $resp = $this->actingAs($u)->patch("http://{$r->subdomain}.plateful.test/account/password", [
        'current_password' => 'wrong',
        'password' => 'NewSecret9!Pass',
        'password_confirmation' => 'NewSecret9!Pass',
    ]);

    $resp->assertSessionHasErrors('current_password');
    expect(Hash::check('password', $u->fresh()->password))->toBeTrue();
});

test('weak password fails validation', function () {
    $r = pwR();
    $u = pwU($r);

    $resp = $this->actingAs($u)->patch("http://{$r->subdomain}.plateful.test/account/password", [
        'current_password' => 'password',
        'password' => 'short',
        'password_confirmation' => 'short',
    ]);

    $resp->assertSessionHasErrors('password');
});

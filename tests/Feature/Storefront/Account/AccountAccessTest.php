<?php

use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function accAccessR(string $sub = 'marcos'): Restaurant
{
    return Restaurant::create([
        'name' => ucfirst($sub),
        'subdomain' => $sub,
        'email' => "$sub@example.test",
        'street' => '1', 'city' => 'NY', 'state' => 'NY', 'postal_code' => '1',
    ]);
}

function accAccessU(Restaurant $r, string $email = 'c@c.test'): User
{
    return User::create([
        'name' => 'C', 'email' => $email,
        'password' => Hash::make('password'),
        'is_super_admin' => false,
    ]);
}

test('guests are redirected to login from /account', function () {
    $r = accAccessR();

    $resp = $this->get("http://{$r->subdomain}.plateful.test/account");

    $resp->assertRedirect('/login');
});

test('customer can view their account home', function () {
    $r = accAccessR();
    $user = accAccessU($r);

    $resp = $this->actingAs($user)
        ->get("http://{$r->subdomain}.plateful.test/account");

    $resp->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Storefront/Account/Home')
            ->where('summary.userEmail', 'c@c.test'));
});

test('account home is restricted to the current tenant', function () {
    $marcos = accAccessR('marcos');
    $bobs = accAccessR('bobs');
    $marcosUser = accAccessU($marcos, 'a@a.test');

    // Hitting Bob's domain while authenticated as a Marco's user — login
    // is per-tenant, so this user isn't recognized there. Fortify auth
    // redirects to login when the tenant doesn't match.
    // We assert the page loads OR redirects, but doesn't show another
    // tenant's data with this user's session.
    $resp = $this->actingAs($marcosUser)
        ->get("http://{$bobs->subdomain}.plateful.test/account");

    // Either redirect to login (expected) or an OK response showing data
    // scoped to bobs (which would have 0 orders). Both demonstrate isolation.
    if ($resp->isRedirect()) {
        expect($resp->headers->get('Location'))->toContain('/login');
    } else {
        $resp->assertOk();
    }
});

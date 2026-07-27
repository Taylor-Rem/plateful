<?php

use App\Models\Restaurant;
use App\Models\User;

const ADMIN_BASE = 'http://admin.plateful.test';

function loginRestaurant(string $subdomain = 'logintest'): Restaurant
{
    return Restaurant::create([
        'name' => 'Login Test',
        'subdomain' => $subdomain,
        'email' => "hello@{$subdomain}.test",
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

test('super admin login on admin host is sent to the two-factor challenge', function () {
    // The superAdmin factory state enrolls 2FA (it is required for platform
    // admins), so a correct password lands on the challenge, not a session.
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->post(ADMIN_BASE.'/login', [
        'email' => $superAdmin->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
    $response->assertRedirect(route('two-factor.login'));
});

test('super admin without two-factor can log in but must enroll', function () {
    $superAdmin = User::factory()->superAdmin()->create([
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
    ]);

    $response = $this->post(ADMIN_BASE.'/login', [
        'email' => $superAdmin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($superAdmin);
    $response->assertRedirect('/');

    $this->get(ADMIN_BASE.'/')
        ->assertRedirect(route('admin.security.edit'));
});

test('restaurant admin (pivot member) can log in on a tenant host', function () {
    // Under the platform-wide-accounts model, any Plateful account can log in
    // at any tenant storefront — admin status doesn't gate tenant login.
    $restaurant = loginRestaurant();
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant->id, ['role' => 'admin']);

    $response = $this->post("http://{$restaurant->subdomain}.plateful.test/login", [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($admin);
    $response->assertRedirect('/');
});

test('a plain customer (no restaurant_user pivot, not super admin) cannot log in on admin host', function () {
    // Admin host requires either is_super_admin OR membership in restaurant_user.
    $customer = User::factory()->create();

    $this->post(ADMIN_BASE.'/login', [
        'email' => $customer->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('register on admin host returns 404', function () {
    $response = $this->get(ADMIN_BASE.'/register');

    $response->assertNotFound();
});

<?php

use App\Enums\UserRole;
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

test('super admin can log in on admin host', function () {
    $superAdmin = User::factory()->superAdmin()->create();

    $response = $this->post(ADMIN_BASE.'/login', [
        'email' => $superAdmin->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticatedAs($superAdmin);
    $response->assertRedirect('/');
});

test('admin login fails on a tenant host', function () {
    $restaurant = loginRestaurant();
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($restaurant->id);

    $this->post("http://{$restaurant->subdomain}.plateful.test/login", [
        'email' => $admin->email,
        'password' => 'password',
    ]);

    $this->assertGuest();
});

test('customer login fails on admin host', function () {
    $restaurant = loginRestaurant();
    $customer = User::factory()->create([
        'restaurant_id' => $restaurant->id,
        'role' => UserRole::Customer,
    ]);

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

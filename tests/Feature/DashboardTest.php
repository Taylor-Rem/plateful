<?php

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $restaurant = Restaurant::create([
        'name' => 'Dash',
        'subdomain' => 'dash',
        'email' => 'hello@dash.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);

    $user = User::factory()->create([
        'restaurant_id' => $restaurant->id,
        'role' => UserRole::Customer,
    ]);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

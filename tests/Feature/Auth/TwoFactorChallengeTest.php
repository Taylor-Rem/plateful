<?php

use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
});

function tfRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'TF Test',
        'subdomain' => 'tftest',
        'email' => 'hello@tftest.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);
}

test('two factor challenge redirects to login when not authenticated', function () {
    $restaurant = tfRestaurant();
    $base = "http://{$restaurant->subdomain}.plateful.test";

    $response = $this->get("{$base}/two-factor-challenge");

    $response->assertRedirect();
});

test('two factor challenge can be rendered', function () {
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]);

    $restaurant = tfRestaurant();
    $base = "http://{$restaurant->subdomain}.plateful.test";

    $user = User::factory()->withTwoFactor()->create();
    RestaurantCustomer::create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
    ]);

    $this->post("{$base}/login", [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->get("{$base}/two-factor-challenge")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/TwoFactorChallenge'),
        );
});

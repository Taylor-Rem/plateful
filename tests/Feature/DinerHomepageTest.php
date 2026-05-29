<?php

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use App\Models\User;

it('renders the diner homepage with the public restaurant grid', function () {
    Restaurant::factory()->create(['name' => 'Marcos Pizza', 'subdomain' => 'marcos']);
    Restaurant::factory()->create(['name' => 'Bobs Cafe', 'subdomain' => 'bobs']);

    Restaurant::factory()->pendingReview()->create(['subdomain' => 'pending']);
    Restaurant::factory()->approved()->create(['subdomain' => 'approved']);
    Restaurant::factory()->suspended()->create(['subdomain' => 'suspended']);
    Restaurant::factory()->inactive()->create(['subdomain' => 'offline']);

    $this->get('http://plateful.test/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Welcome')
            ->has('restaurants', 2)
            ->where('authUserName', null));
});

it('passes the authenticated user name when logged in', function () {
    $user = User::factory()->create(['name' => 'Marco']);

    $this->actingAs($user)
        ->get('http://plateful.test/')
        ->assertInertia(fn ($page) => $page->where('authUserName', 'Marco'));
});

it('still renders when no restaurants are live yet', function () {
    Restaurant::factory()->pendingReview()->create();

    $this->get('http://plateful.test/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('restaurants', 0));
});

it('hides restaurants whose status is not active from the diner grid', function () {
    Restaurant::factory()->create(['status' => RestaurantStatus::Active, 'is_active' => true, 'subdomain' => 'live1']);
    Restaurant::factory()->approved()->create(['subdomain' => 'live2-but-approved']);

    $this->get('http://plateful.test/')
        ->assertInertia(fn ($page) => $page
            ->has('restaurants', 1)
            ->where('restaurants.0.url', fn ($url) => str_contains($url, 'live1.plateful.test')));
});

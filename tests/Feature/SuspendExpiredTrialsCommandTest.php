<?php

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;

it('suspends restaurants whose trial expired without a subscription', function () {
    $expired = Restaurant::factory()->create([
        'status' => RestaurantStatus::Active,
        'is_active' => true,
        'trial_ends_at' => now()->subDay(),
    ]);

    $stillOnTrial = Restaurant::factory()->create([
        'status' => RestaurantStatus::Active,
        'is_active' => true,
        'trial_ends_at' => now()->addDays(3),
    ]);

    $this->artisan('platform:suspend-expired-trials')->assertSuccessful();

    expect($expired->fresh()->status)->toBe(RestaurantStatus::Suspended)
        ->and($expired->fresh()->suspended_at)->not->toBeNull()
        ->and($stillOnTrial->fresh()->status)->toBe(RestaurantStatus::Active);
});

it('ignores restaurants that are not active', function () {
    $pending = Restaurant::factory()->approved()->create([
        'trial_ends_at' => now()->subDay(),
    ]);

    $this->artisan('platform:suspend-expired-trials')->assertSuccessful();

    expect($pending->fresh()->status)->toBe(RestaurantStatus::Approved);
});

it('does not suspend restaurants without a trial set', function () {
    $noTrial = Restaurant::factory()->create([
        'status' => RestaurantStatus::Active,
        'is_active' => true,
        'trial_ends_at' => null,
    ]);

    $this->artisan('platform:suspend-expired-trials')->assertSuccessful();

    expect($noTrial->fresh()->status)->toBe(RestaurantStatus::Active);
});

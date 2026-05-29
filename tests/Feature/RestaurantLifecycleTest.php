<?php

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;

it('casts the status column to the RestaurantStatus enum', function () {
    $restaurant = Restaurant::factory()->pendingReview()->create();

    expect($restaurant->fresh()->status)->toBe(RestaurantStatus::PendingReview);
});

it('public scope only returns active + is_active restaurants', function () {
    $live = Restaurant::factory()->create(); // active + is_active by default
    Restaurant::factory()->pendingReview()->create();
    Restaurant::factory()->approved()->create();
    Restaurant::factory()->suspended()->create();
    Restaurant::factory()->inactive()->create(); // status active but is_active false

    $ids = Restaurant::query()->public()->pluck('id')->all();

    expect($ids)->toBe([$live->id]);
});

it('isLive() reflects status + is_active', function () {
    $live = Restaurant::factory()->create();
    $offline = Restaurant::factory()->inactive()->create();
    $pending = Restaurant::factory()->pendingReview()->create();

    expect($live->isLive())->toBeTrue()
        ->and($offline->isLive())->toBeFalse()
        ->and($pending->isLive())->toBeFalse();
});

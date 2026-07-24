<?php

use App\Enums\RestaurantStatus;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Support\Onboarding\OnboardingSteps;

function stepsRestaurant(): Restaurant
{
    return Restaurant::factory()->approved()->create([
        'is_active' => true,
        'subdomain' => 'stepsjoint',
    ]);
}

function stepsAddHours(Restaurant $r): void
{
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 1,
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);
}

function stepsAddMenuItem(Restaurant $r): void
{
    $cat = MenuCategory::create([
        'restaurant_id' => $r->id,
        'name' => 'Pizza',
        'slug' => 'pizza',
        'position' => 0,
        'is_active' => true,
    ]);

    MenuItem::create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'item_template_id' => null,
        'name' => 'Plain',
        'slug' => 'plain',
        'price_cents' => 1000,
        'is_available' => true,
        'position' => 0,
    ]);
}

function stepsMarkStripeReady(Restaurant $r): void
{
    $r->forceFill([
        'stripe_account_id' => 'acct_test',
        'stripe_account_status' => Restaurant::STRIPE_ENABLED,
    ])->save();
}

test('canGoLive requires hours, menu, and stripe', function () {
    $steps = app(OnboardingSteps::class);
    $restaurant = stepsRestaurant();

    expect($steps->canGoLive($restaurant))->toBeFalse();

    stepsAddHours($restaurant);
    stepsAddMenuItem($restaurant);
    expect($steps->canGoLive($restaurant->fresh()))->toBeFalse();

    stepsMarkStripeReady($restaurant);
    expect($steps->canGoLive($restaurant->fresh()))->toBeTrue();
});

test('canGoLive is false outside the approved status', function () {
    $steps = app(OnboardingSteps::class);
    $restaurant = stepsRestaurant();
    stepsAddHours($restaurant);
    stepsAddMenuItem($restaurant);
    stepsMarkStripeReady($restaurant);

    $restaurant->forceFill(['status' => RestaurantStatus::Active])->save();

    expect($steps->canGoLive($restaurant->fresh()))->toBeFalse();
});

test('remaining lists incomplete steps and excludes go-live', function () {
    $steps = app(OnboardingSteps::class);
    $restaurant = stepsRestaurant();
    stepsAddHours($restaurant);

    $remainingKeys = array_column($steps->remaining($restaurant->fresh()), 'key');

    expect($remainingKeys)->toContain('menu', 'stripe')
        ->not->toContain('hours', 'review');
});

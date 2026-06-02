<?php

use App\Enums\RestaurantStatus;
use App\Models\MenuItem;
use App\Models\Restaurant;

it('deactivates a restaurant by default, preserving its data', function () {
    $this->artisan('make:restaurant', [
        'name' => 'Soft Spot',
        '--subdomain' => 'soft-spot',
        '--menu' => 'thai',
    ])->assertSuccessful();

    $this->artisan('unmake:restaurant', ['subdomain' => 'soft-spot'])->assertSuccessful();

    $restaurant = Restaurant::query()->where('subdomain', 'soft-spot')->first();

    expect($restaurant)->not->toBeNull()
        ->and($restaurant->is_active)->toBeFalse()
        ->and($restaurant->status)->toBe(RestaurantStatus::Suspended)
        ->and($restaurant->suspended_at)->not->toBeNull()
        ->and(MenuItem::withoutTenantScope()->where('restaurant_id', $restaurant->id)->count())->toBeGreaterThan(0);
});

it('hard-deletes a restaurant and cascades its data when forced', function () {
    $this->artisan('make:restaurant', [
        'name' => 'Goner',
        '--subdomain' => 'goner',
        '--menu' => 'sushi',
    ])->assertSuccessful();

    $id = Restaurant::query()->where('subdomain', 'goner')->value('id');

    $this->artisan('unmake:restaurant', [
        'subdomain' => 'goner',
        '--hard' => true,
        '--force' => true,
    ])->assertSuccessful();

    expect(Restaurant::query()->where('subdomain', 'goner')->exists())->toBeFalse()
        ->and(MenuItem::withoutTenantScope()->where('restaurant_id', $id)->count())->toBe(0);
});

it('errors when the subdomain does not exist', function () {
    $this->artisan('unmake:restaurant', ['subdomain' => 'nope'])->assertFailed();
});

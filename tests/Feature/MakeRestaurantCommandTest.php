<?php

use App\Enums\RestaurantStatus;
use App\Models\ItemTemplate;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\User;

it('scaffolds a live, orderable restaurant by default', function () {
    $this->artisan('make:restaurant', [
        'name' => 'Taco Town',
        '--subdomain' => 'taco-town',
        '--menu' => 'mexican',
    ])->assertSuccessful();

    $restaurant = Restaurant::query()->where('subdomain', 'taco-town')->first();

    expect($restaurant)->not->toBeNull()
        ->and($restaurant->status)->toBe(RestaurantStatus::Active)
        ->and($restaurant->is_active)->toBeTrue()
        ->and($restaurant->onboarding_completed_at)->not->toBeNull()
        ->and($restaurant->stripe_account_status)->toBe(Restaurant::STRIPE_ENABLED)
        ->and($restaurant->isStripeReady())->toBeTrue()
        ->and($restaurant->isLive())->toBeTrue();

    // Owner exists with predictable credentials and admin membership.
    $owner = User::query()->where('email', 'owner@taco-town.test')->first();
    expect($owner)->not->toBeNull()
        ->and($owner->email_verified_at)->not->toBeNull();

    $pivot = $restaurant->members()->where('users.id', $owner->id)->first();
    expect($pivot)->not->toBeNull()
        ->and($pivot->pivot->role)->toBe('admin');

    // Hours for every day, and a flat menu with no customization template.
    expect(RestaurantHour::query()->where('restaurant_id', $restaurant->id)->count())->toBe(7)
        ->and(MenuItem::query()->where('restaurant_id', $restaurant->id)->count())->toBeGreaterThan(0)
        ->and(ItemTemplate::withoutTenantScope()->where('restaurant_id', $restaurant->id)->count())->toBe(0);
});

it('derives the subdomain from the name when omitted', function () {
    $this->artisan('make:restaurant', ['name' => 'Luigi Bistro'])->assertSuccessful();

    expect(Restaurant::query()->where('subdomain', 'luigi-bistro')->exists())->toBeTrue();
});

it('builds the configurable pizza template for the italian preset', function () {
    $this->artisan('make:restaurant', [
        'name' => "Luigi's",
        '--subdomain' => 'luigis',
        '--menu' => 'italian',
    ])->assertSuccessful();

    $restaurant = Restaurant::query()->where('subdomain', 'luigis')->first();

    $template = ItemTemplate::withoutTenantScope()
        ->where('restaurant_id', $restaurant->id)
        ->first();

    expect($template)->not->toBeNull()
        ->and($template->name)->toBe('Pizza');

    $pizza = MenuItem::withoutTenantScope()
        ->where('restaurant_id', $restaurant->id)
        ->where('name', 'Margherita Pizza')
        ->first();

    expect($pizza)->not->toBeNull()
        ->and($pizza->item_template_id)->toBe($template->id)
        ->and($pizza->defaultSelections()->count())->toBeGreaterThan(0);
});

it('stops before onboarding when --stop=onboarding is passed', function () {
    $this->artisan('make:restaurant', [
        'name' => 'Halfway House',
        '--subdomain' => 'halfway',
        '--stop' => 'onboarding',
    ])->assertSuccessful();

    $restaurant = Restaurant::query()->where('subdomain', 'halfway')->first();

    expect($restaurant->status)->toBe(RestaurantStatus::Approved)
        ->and($restaurant->onboarding_completed_at)->toBeNull()
        ->and($restaurant->stripe_account_status)->toBeNull()
        ->and(RestaurantHour::query()->where('restaurant_id', $restaurant->id)->count())->toBe(0)
        ->and(MenuItem::withoutTenantScope()->where('restaurant_id', $restaurant->id)->count())->toBe(0);

    // Owner and membership still exist so the owner can log in and onboard.
    expect(User::query()->where('email', 'owner@halfway.test')->exists())->toBeTrue();
});

it('rejects an unknown cuisine', function () {
    $this->artisan('make:restaurant', [
        'name' => 'Mystery',
        '--menu' => 'klingon',
    ])->assertExitCode(2);

    expect(Restaurant::query()->where('subdomain', 'mystery')->exists())->toBeFalse();
});

it('fails when the subdomain is already taken', function () {
    Restaurant::factory()->create(['subdomain' => 'dupe']);

    $this->artisan('make:restaurant', [
        'name' => 'Dupe',
        '--subdomain' => 'dupe',
    ])->assertFailed();
});

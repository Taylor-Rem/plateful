<?php

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\User;

const ADMIN_HOST = 'http://admin.plateful.test';

/**
 * @return array{0: User, 1: Restaurant}
 */
function makeOwnerAndApprovedRestaurant(): array
{
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->approved()->create([
        'is_active' => true,
        'subdomain' => 'pizzajoint',
    ]);
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    return [$owner, $restaurant];
}

function addHours(Restaurant $r): void
{
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 1,
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);
}

function markStripeReady(Restaurant $r): void
{
    $r->forceFill([
        'stripe_account_id' => 'acct_test',
        'stripe_account_status' => Restaurant::STRIPE_ENABLED,
    ])->save();
}

function addMenuItem(Restaurant $r): MenuItem
{
    $cat = MenuCategory::create([
        'restaurant_id' => $r->id,
        'name' => 'Pizza',
        'slug' => 'pizza',
        'position' => 0,
        'is_active' => true,
    ]);

    return MenuItem::create([
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

it('renders the onboarding checklist for the restaurant admin', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/Onboarding')
            ->where('restaurant.subdomain', 'pizzajoint')
            ->where('canGoLive', false)
            ->has('steps', 4));
});

it('marks hours and menu steps complete once the data exists', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();
    addHours($restaurant);
    addMenuItem($restaurant);
    markStripeReady($restaurant);

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertInertia(fn ($page) => $page
            ->where('canGoLive', true)
            ->where('steps.1.key', 'hours')
            ->where('steps.1.complete', true)
            ->where('steps.2.key', 'menu')
            ->where('steps.2.complete', true));
});

it('blocks non-admin members from the onboarding page', function () {
    [, $restaurant] = makeOwnerAndApprovedRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertForbidden();
});

it('blocks unrelated users from the onboarding page', function () {
    [, $restaurant] = makeOwnerAndApprovedRestaurant();
    $other = User::factory()->create();

    $this->actingAs($other)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertForbidden();
});

it('records a custom domain request without flipping the live custom_domain', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/custom-domain", [
            'pending_custom_domain' => 'Pizzajoint.COM',
        ])
        ->assertRedirect();

    $restaurant->refresh();
    expect($restaurant->pending_custom_domain)->toBe('pizzajoint.com')
        ->and($restaurant->custom_domain_requested_at)->not->toBeNull()
        ->and($restaurant->custom_domain)->toBeNull();
});

it('rejects invalid custom domain formats', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/custom-domain", [
            'pending_custom_domain' => 'not a domain',
        ])
        ->assertSessionHasErrors('pending_custom_domain');
});

it('refuses to go live when required steps are incomplete', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/go-live")
        ->assertSessionHasErrors('go_live');

    expect($restaurant->fresh()->status)->toBe(RestaurantStatus::Approved);
});

it('refuses to go live without Stripe connected even when hours and menu exist', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();
    addHours($restaurant);
    addMenuItem($restaurant);

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertInertia(fn ($page) => $page->where('canGoLive', false));

    $this->actingAs($owner)
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/go-live")
        ->assertSessionHasErrors('go_live');

    expect($restaurant->fresh()->status)->toBe(RestaurantStatus::Approved);
});

it('goes live when required steps are complete and appears on the diner homepage', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();
    addHours($restaurant);
    addMenuItem($restaurant);
    markStripeReady($restaurant);

    $this->actingAs($owner)
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/go-live")
        ->assertRedirect(ADMIN_HOST."/{$restaurant->subdomain}/dashboard");

    $restaurant->refresh();
    expect($restaurant->status)->toBe(RestaurantStatus::Active)
        ->and($restaurant->is_active)->toBeTrue()
        ->and($restaurant->onboarding_completed_at)->not->toBeNull()
        ->and($restaurant->isLive())->toBeTrue();

    expect(Restaurant::query()->public()->whereKey($restaurant->id)->exists())->toBeTrue();
});

it('cannot go live a second time', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();
    addHours($restaurant);
    addMenuItem($restaurant);
    markStripeReady($restaurant);

    $this->actingAs($owner)->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/go-live");

    $this->actingAs($owner)
        ->from(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/go-live")
        ->assertRedirect();

    expect($restaurant->fresh()->status)->toBe(RestaurantStatus::Active);
});

it('redirects single-restaurant owners with approved restaurant to onboarding', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->get(ADMIN_HOST)
        ->assertRedirect(ADMIN_HOST."/{$restaurant->subdomain}/onboarding");
});

it('redirects single-restaurant owners with active restaurant to dashboard', function () {
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->create([
        'subdomain' => 'pizzajoint',
        'is_active' => true,
        'status' => RestaurantStatus::Active,
    ]);
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    $this->actingAs($owner)
        ->get(ADMIN_HOST)
        ->assertRedirect(ADMIN_HOST."/{$restaurant->subdomain}/dashboard");
});

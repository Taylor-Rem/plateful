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

it('renders the onboarding wizard for the restaurant admin', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/Onboarding')
            ->where('restaurant.subdomain', 'pizzajoint')
            ->where('canGoLive', false)
            ->has('steps', 6)
            ->has('menuPresets')
            ->where('menuSummary.items', 0));
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

it('keeps the onboarding page accessible after the restaurant goes live', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();
    addHours($restaurant);
    addMenuItem($restaurant);
    markStripeReady($restaurant);

    $this->actingAs($owner)->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/go-live");
    expect($restaurant->fresh()->isLive())->toBeTrue();

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/Onboarding')
            ->where('restaurant.isLive', true));
});

it('still reports the outstanding stripe step for a restaurant that went live without one', function () {
    // Restaurants created before the Stripe gate existed are Active without a
    // connected account. The nav shows a "Finish setup" dot for them, so the
    // onboarding page has to keep reporting what's outstanding.
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();
    addHours($restaurant);
    addMenuItem($restaurant);
    $restaurant->forceFill([
        'status' => RestaurantStatus::Active,
        'onboarding_completed_at' => now(),
    ])->save();

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('restaurant.isLive', true)
            ->where('restaurant.isStripeReady', false)
            ->where('steps.3.key', 'stripe')
            ->where('steps.3.required', true)
            ->where('steps.3.complete', false));
});

it('exposes live and stripe status with the admin role so the setup nav can render for admins', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/dashboard")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentRestaurantRole', 'admin')
            ->where('restaurant.isLive', false)
            ->where('restaurant.isStripeReady', false));
});

it('marks once-live, stripe-ready restaurants as setup-complete for the admin nav', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();
    addHours($restaurant);
    addMenuItem($restaurant);
    markStripeReady($restaurant);
    $this->actingAs($owner)->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/go-live");

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/dashboard")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('restaurant.isLive', true)
            ->where('restaurant.isStripeReady', true));
});

it('marks staff with the staff role so the setup nav stays hidden for them', function () {
    [, $restaurant] = makeOwnerAndApprovedRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/dashboard")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('currentRestaurantRole', 'staff'));
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

    // Returns to the wizard, which renders the "you're live" celebration.
    $this->actingAs($owner)
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/go-live")
        ->assertRedirect(ADMIN_HOST."/{$restaurant->subdomain}/onboarding");

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

it('saves the basics step and marks it complete', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->put(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/basics", [
            'name' => 'Pizza Joint',
            'description' => 'Wood-fired pizza in Brooklyn.',
            'phone' => '555-0100',
            'primary_color' => '#aa2222',
            'secondary_color' => '#ffffff',
            'street' => '1 Main St',
            'city' => 'Brooklyn',
            'state' => 'ny',
            'postal_code' => '11201',
        ])
        ->assertRedirect();

    $restaurant->refresh();
    expect($restaurant->name)->toBe('Pizza Joint')
        ->and($restaurant->description)->toBe('Wood-fired pizza in Brooklyn.')
        ->and($restaurant->street)->toBe('1 Main St')
        ->and($restaurant->state)->toBe('NY');

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertInertia(fn ($page) => $page
            ->where('steps.0.key', 'basics')
            ->where('steps.0.complete', true));
});

it('blocks staff from saving the basics step', function () {
    [, $restaurant] = makeOwnerAndApprovedRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->put(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/basics", ['name' => 'Hacked'])
        ->assertForbidden();
});

it('seeds a starter menu from a preset while the menu is empty', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/menu-preset", ['preset' => 'mexican'])
        ->assertRedirect();

    expect($restaurant->menuItems()->exists())->toBeTrue()
        ->and($restaurant->menuCategories()->exists())->toBeTrue();
});

it('refuses to apply a preset once the menu has items', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();
    addMenuItem($restaurant);
    $itemCount = $restaurant->menuItems()->count();

    $this->actingAs($owner)
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/menu-preset", ['preset' => 'mexican'])
        ->assertSessionHasErrors('preset');

    expect($restaurant->menuItems()->count())->toBe($itemCount);
});

it('rejects an unknown menu preset', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->post(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/menu-preset", ['preset' => 'klingon'])
        ->assertSessionHasErrors('preset');
});

it('hands the owner to the storefront preview with a token', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/preview")
        ->assertRedirectContains("http://{$restaurant->subdomain}.plateful.test/preview/enter?token=");
});

it('updates the restaurant timezone alongside hours', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->put(ADMIN_HOST."/{$restaurant->subdomain}/hours", [
            'windows' => [1 => [['opens_at' => '11:00', 'closes_at' => '21:00']]],
            'timezone' => 'America/Denver',
        ])
        ->assertRedirect();

    expect($restaurant->fresh()->timezone)->toBe('America/Denver');
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

test('the wizard exposes a refund policy step that starts incomplete and off', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->get(ADMIN_HOST."/{$restaurant->subdomain}/onboarding")
        ->assertInertia(fn ($page) => $page
            ->where('steps', fn ($steps) => collect($steps)->contains(
                fn ($s) => $s['key'] === 'refunds' && $s['complete'] === false && $s['required'] === false,
            ))
        );

    expect($restaurant->fresh()->pickup_refunds_enabled)->toBeFalse()
        ->and($restaurant->fresh()->delivery_refunds_enabled)->toBeFalse()
        ->and($restaurant->fresh()->refund_policy_reviewed_at)->toBeNull();
});

test('saving the refund policy step stores the toggles and marks it reviewed', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->put(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/refund-policy", [
            'pickup_refunds_enabled' => false,
            'delivery_refunds_enabled' => true,
        ])
        ->assertRedirect();

    $restaurant->refresh();
    expect($restaurant->pickup_refunds_enabled)->toBeFalse()
        ->and($restaurant->delivery_refunds_enabled)->toBeTrue()
        ->and($restaurant->refund_policy_reviewed_at)->not->toBeNull();
});

test('reviewing the refund policy with both off still completes the step', function () {
    [$owner, $restaurant] = makeOwnerAndApprovedRestaurant();

    $this->actingAs($owner)
        ->put(ADMIN_HOST."/{$restaurant->subdomain}/onboarding/refund-policy", [
            'pickup_refunds_enabled' => false,
            'delivery_refunds_enabled' => false,
        ])
        ->assertRedirect();

    // A deliberate "no refunds" choice is still a completed step (does not
    // block go-live either, since the step is optional).
    expect($restaurant->fresh()->refund_policy_reviewed_at)->not->toBeNull();
});

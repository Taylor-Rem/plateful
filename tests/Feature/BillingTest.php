<?php

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use App\Models\RestaurantSignup;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

const ADMIN_BILL = 'http://admin.plateful.test';

/**
 * @return array{0: User, 1: Restaurant}
 */
function billingOwnerAndRestaurant(array $overrides = []): array
{
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->create(array_merge([
        'subdomain' => 'pizzajoint',
        'status' => RestaurantStatus::Active,
        'is_active' => true,
        'trial_ends_at' => now()->addDays(10),
    ], $overrides));
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    return [$owner, $restaurant];
}

it('approving a signup starts a 14-day trial by default', function () {
    Mail::fake();
    config()->set('platform.billing.trial_days', 14);

    $superAdmin = User::factory()->create(['is_super_admin' => true]);
    $owner = User::factory()->create();
    $signup = RestaurantSignup::factory()->for($owner)->create(['proposed_subdomain' => 'newshop']);

    $this->actingAs($superAdmin)
        ->post(ADMIN_BILL."/super/signups/{$signup->id}/approve")
        ->assertRedirect();

    $restaurant = Restaurant::where('subdomain', 'newshop')->firstOrFail();

    expect($restaurant->trial_ends_at)->not->toBeNull()
        ->and(round(abs($restaurant->trial_ends_at->diffInDays(now()))))->toBe(14.0);
});

it('billing page renders for the restaurant admin with trial details', function () {
    [$owner, $restaurant] = billingOwnerAndRestaurant();

    $this->actingAs($owner)
        ->get(ADMIN_BILL."/{$restaurant->subdomain}/billing")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/Billing')
            ->where('billing.onTrial', true)
            ->where('billing.isSubscribed', false)
            ->has('billing.trialEndsAt'));
});

it('billing page is admin-only', function () {
    [, $restaurant] = billingOwnerAndRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->get(ADMIN_BILL."/{$restaurant->subdomain}/billing")
        ->assertForbidden();
});

it('checkout falls back gracefully when no Stripe price is configured', function () {
    config()->set('platform.billing.stripe_price', null);

    [$owner, $restaurant] = billingOwnerAndRestaurant();

    $this->actingAs($owner)
        ->from(ADMIN_BILL."/{$restaurant->subdomain}/billing")
        ->post(ADMIN_BILL."/{$restaurant->subdomain}/billing/checkout")
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('portal refuses to redirect when no Stripe customer exists yet', function () {
    [$owner, $restaurant] = billingOwnerAndRestaurant();

    expect($restaurant->hasStripeId())->toBeFalse();

    $this->actingAs($owner)
        ->from(ADMIN_BILL."/{$restaurant->subdomain}/billing")
        ->post(ADMIN_BILL."/{$restaurant->subdomain}/billing/portal")
        ->assertRedirect()
        ->assertSessionHas('error');
});

it('suspended restaurants render an Unavailable page on the storefront host', function () {
    Restaurant::factory()->suspended()->create(['subdomain' => 'gone', 'is_active' => true]);

    $this->get('http://gone.plateful.test/')
        ->assertStatus(503);
});

it('approved-but-not-live restaurants are NOT served on the storefront host', function () {
    Restaurant::factory()->approved()->create(['subdomain' => 'soon', 'is_active' => true]);

    $this->get('http://soon.plateful.test/')
        ->assertStatus(503);
});

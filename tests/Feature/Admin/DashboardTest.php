<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Enums\RestaurantStatus;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;

const DASH_ADMIN_BASE = 'http://admin.plateful.test';

/**
 * @return array{0: User, 1: Restaurant}
 */
function dashboardSetup(string $timezone = 'America/Los_Angeles'): array
{
    $admin = User::factory()->admin()->create();
    $restaurant = Restaurant::factory()->create([
        'subdomain' => 'dashjoint',
        'timezone' => $timezone,
        'status' => RestaurantStatus::Active,
        'is_active' => true,
    ]);
    $admin->restaurants()->attach($restaurant->id);

    return [$admin, $restaurant];
}

function dashboardOrder(Restaurant $restaurant, array $overrides = []): Order
{
    return Order::factory()->create(array_merge([
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed,
        'payment_state' => PaymentState::Captured,
    ], $overrides));
}

test('today respects the restaurant timezone boundary', function () {
    [$admin, $restaurant] = dashboardSetup('America/Los_Angeles');

    $localMidnightUtc = CarbonImmutable::now('America/Los_Angeles')->startOfDay()->utc();

    // 23:59 local yesterday — excluded even though it may be "today" in UTC.
    dashboardOrder($restaurant, ['placed_at' => $localMidnightUtc->subMinute()]);
    // 00:01 local today — included.
    dashboardOrder($restaurant, ['placed_at' => $localMidnightUtc->addMinute()]);

    $this->actingAs($admin)->get(DASH_ADMIN_BASE.'/dashjoint/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.ordersToday', 1)
            ->where('stats.timezone', 'America/Los_Angeles'));
});

test('cancelled orders are excluded from the day counts', function () {
    [$admin, $restaurant] = dashboardSetup();

    dashboardOrder($restaurant, ['total_cents' => 2000]);
    dashboardOrder($restaurant, [
        'status' => OrderStatus::Cancelled,
        'total_cents' => 9900,
    ]);

    $this->actingAs($admin)->get(DASH_ADMIN_BASE.'/dashjoint/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.ordersToday', 1)
            ->where('stats.revenueTodayCents', 2000));
});

test('authorized holds count as orders but not revenue', function () {
    [$admin, $restaurant] = dashboardSetup();

    dashboardOrder($restaurant, ['total_cents' => 2000]);
    dashboardOrder($restaurant, [
        'payment_state' => PaymentState::Authorized,
        'total_cents' => 3000,
    ]);

    $this->actingAs($admin)->get(DASH_ADMIN_BASE.'/dashjoint/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.ordersToday', 2)
            ->where('stats.revenueTodayCents', 2000)
            ->where('stats.avgTicketCents', 2000));
});

test('refunds are netted out of revenue', function () {
    [$admin, $restaurant] = dashboardSetup();

    dashboardOrder($restaurant, ['total_cents' => 2000, 'refunded_cents' => 500]);

    $this->actingAs($admin)->get(DASH_ADMIN_BASE.'/dashjoint/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.revenueTodayCents', 1500));
});

test('avg ticket is null with no captured orders', function () {
    [$admin, $restaurant] = dashboardSetup();

    dashboardOrder($restaurant, [
        'payment_state' => PaymentState::Authorized,
    ]);

    $this->actingAs($admin)->get(DASH_ADMIN_BASE.'/dashjoint/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.avgTicketCents', null)
            ->where('stats.revenueTodayCents', 0));
});

test('pending count ignores the day window', function () {
    [$admin, $restaurant] = dashboardSetup();

    dashboardOrder($restaurant, [
        'status' => OrderStatus::Pending,
        'placed_at' => now()->subDays(3),
    ]);

    $this->actingAs($admin)->get(DASH_ADMIN_BASE.'/dashjoint/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.pendingCount', 1)
            ->where('stats.ordersToday', 0));
});

test('recent orders are capped at six and carry no line items', function () {
    [$admin, $restaurant] = dashboardSetup();

    foreach (range(1, 8) as $i) {
        dashboardOrder($restaurant, ['placed_at' => now()->subMinutes($i)]);
    }

    $this->actingAs($admin)->get(DASH_ADMIN_BASE.'/dashjoint/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('recentOrders', 6)
            ->missing('recentOrders.0.items'));
});

test('setup is null when the restaurant is live', function () {
    [$admin] = dashboardSetup();

    $this->actingAs($admin)->get(DASH_ADMIN_BASE.'/dashjoint/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('setup', null));
});

test('setup lists remaining steps for an approved restaurant', function () {
    $admin = User::factory()->admin()->create();
    $restaurant = Restaurant::factory()->approved()->create([
        'subdomain' => 'dashjoint',
        'is_active' => true,
    ]);
    $admin->restaurants()->attach($restaurant->id);

    $this->actingAs($admin)->get(DASH_ADMIN_BASE.'/dashjoint/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('setup.canGoLive', false)
            ->has('setup.remaining'));
});

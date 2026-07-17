<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\RestaurantRole;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Stripe\StripeConnectService;
use Stripe\Collection;
use Stripe\Payout;
use Stripe\StripeClient;

const PAYOUTS_ADMIN = 'http://admin.plateful.test';

/**
 * @return array{0: User, 1: Restaurant}
 */
function payoutsOwnerAndRestaurant(bool $stripeReady = true): array
{
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'pizzajoint', 'is_active' => true]);
    if ($stripeReady) {
        $restaurant->forceFill([
            'stripe_account_id' => 'acct_payouts',
            'stripe_account_status' => Restaurant::STRIPE_ENABLED,
        ])->save();
    }
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    return [$owner, $restaurant];
}

function makePaidOrder(Restaurant $r, int $feeCents, array $overrides = []): Order
{
    return Order::create(array_merge([
        'restaurant_id' => $r->id,
        'customer_name' => 'A', 'customer_email' => 'a@a.test',
        'number' => 'PIZ-'.str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT),
        'status' => OrderStatus::Completed,
        'type' => OrderType::Pickup,
        'subtotal_cents' => 1000, 'tax_cents' => 0, 'tip_cents' => 0,
        'delivery_fee_cents' => 0, 'application_fee_cents' => $feeCents,
        'platform_commission_cents' => $feeCents, 'total_cents' => 1000,
        'placed_at' => now(),
    ], $overrides));
}

function fakePayouts(array $payouts = [], bool $hasMore = false): void
{
    $mock = Mockery::mock(StripeConnectService::class, [app(StripeClient::class)])->makePartial();
    $mock->shouldReceive('listPayouts')->andReturn(Collection::constructFrom([
        'data' => array_map(fn ($p) => Payout::constructFrom($p), $payouts),
        'has_more' => $hasMore,
    ]));
    app()->instance(StripeConnectService::class, $mock);
}

it('renders payouts and YTD Plateful fees for the restaurant admin', function () {
    [$owner, $restaurant] = payoutsOwnerAndRestaurant();
    makePaidOrder($restaurant, 14);
    makePaidOrder($restaurant, 50);
    // Refunded order — its fee was reversed, so it must NOT count.
    makePaidOrder($restaurant, 99, ['refunded_at' => now(), 'refunded_cents' => 1000]);
    // Last year — out of the YTD window.
    makePaidOrder($restaurant, 1000, ['placed_at' => now()->subYear()]);

    fakePayouts([
        ['id' => 'po_1', 'amount' => 2372, 'currency' => 'usd', 'status' => 'paid', 'arrival_date' => 1700000000, 'created' => 1699990000],
    ]);

    $this->actingAs($owner)
        ->get(PAYOUTS_ADMIN."/{$restaurant->subdomain}/payouts")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/Payouts')
            ->where('ytdFeesCents', 64)
            ->where('stripeConnected', true)
            ->has('payouts', 1)
            ->where('payouts.0.amountCents', 2372)
            ->where('payouts.0.status', 'paid'));
});

it('does not call Stripe and shows a not-connected state when Stripe is not ready', function () {
    [$owner, $restaurant] = payoutsOwnerAndRestaurant(stripeReady: false);
    makePaidOrder($restaurant, 14);

    // No payouts mock bound — if the controller called Stripe it would error.
    $this->actingAs($owner)
        ->get(PAYOUTS_ADMIN."/{$restaurant->subdomain}/payouts")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stripeConnected', false)
            ->where('ytdFeesCents', 14)
            ->has('payouts', 0));
});

it('is admin-only — staff cannot see payouts', function () {
    [, $restaurant] = payoutsOwnerAndRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->get(PAYOUTS_ADMIN."/{$restaurant->subdomain}/payouts")
        ->assertForbidden();
});

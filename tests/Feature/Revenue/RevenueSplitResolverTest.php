<?php

use App\Enums\RevenueRole;
use App\Models\FeeDistribution;
use App\Models\Order;
use App\Models\PlatformRoleHolder;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\RevenueSplitResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.revenue_shares' => ['founder' => 10, 'recruiter' => 0, 'overseer' => 90]]);
    $this->resolver = app(RevenueSplitResolver::class);
});

/**
 * @return array{amount: int}[] keyed by user id => total cents
 */
function totalsByUser(array $slices): array
{
    $out = [];
    foreach ($slices as $slice) {
        $id = $slice['user']?->id ?? 0;
        $out[$id] = ($out[$id] ?? 0) + $slice['amountCents'];
    }

    return $out;
}

test('an unassigned overseer falls back to the operator, stacking with founder', function () {
    $taylor = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);

    $restaurant = Restaurant::factory()->create(['overseer_id' => null]);

    $slices = $this->resolver->splitFor($restaurant, 1000);

    // Two slices (founder + overseer), both to Taylor, summing to the whole fee.
    expect($slices)->toHaveCount(2);
    expect(totalsByUser($slices))->toBe([$taylor->id => 1000]);
    expect($slices[0]['amountCents'])->toBe(100); // founder 10%
    expect($slices[1]['amountCents'])->toBe(900); // overseer 90%
});

test('an assigned overseer earns the overseer share, founder keeps 10 percent', function () {
    $taylor = User::factory()->create();
    $ben = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);

    $restaurant = Restaurant::factory()->create(['overseer_id' => $ben->id]);

    $slices = $this->resolver->splitFor($restaurant, 1000);

    expect(totalsByUser($slices))->toBe([
        $taylor->id => 100,
        $ben->id => 900,
    ]);
});

test('the recruiter earns nothing while its share is zero, even when assigned', function () {
    $taylor = User::factory()->create();
    $ben = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);

    $restaurant = Restaurant::factory()->create([
        'recruiter_id' => $ben->id,
        'overseer_id' => $taylor->id,
    ]);

    $slices = $this->resolver->splitFor($restaurant, 1000);

    $roles = array_map(fn ($s) => $s['role'], $slices);
    expect($roles)->not->toContain(RevenueRole::Recruiter);
});

test('cents always sum to the fee under largest-remainder rounding', function (int $fee) {
    $taylor = User::factory()->create();
    $ben = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);
    $restaurant = Restaurant::factory()->create(['overseer_id' => $ben->id]);

    $slices = $this->resolver->splitFor($restaurant, $fee);

    expect(array_sum(array_column($slices, 'amountCents')))->toBe($fee);
})->with([1, 3, 7, 64, 101, 999, 1234]);

test('record() writes one distribution per slice and is idempotent', function () {
    $taylor = User::factory()->create();
    $ben = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);
    $restaurant = Restaurant::factory()->create(['overseer_id' => $ben->id]);

    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'platform_commission_cents' => 1000,
    ]);

    $this->resolver->record($order);
    $this->resolver->record($order); // replay must not double-write

    expect(FeeDistribution::where('order_id', $order->id)->count())->toBe(2);
    expect((int) FeeDistribution::where('order_id', $order->id)->sum('amount_cents'))->toBe(1000);
});

test('the split reads platform_commission_cents, not the Stripe gross', function () {
    $taylor = User::factory()->create();
    $ben = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);
    $restaurant = Restaurant::factory()->create(['overseer_id' => $ben->id]);

    // A delivery order whose Stripe gross (application_fee_cents) is far larger
    // than the commission because it carries DoorDash's passthrough + tip.
    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'application_fee_cents' => 5000,
        'platform_commission_cents' => 1000,
        'delivery_margin_cents' => 0,
    ]);

    $this->resolver->record($order);

    // Only the 1000 commission is distributed — never the 5000 gross.
    expect((int) FeeDistribution::where('order_id', $order->id)->sum('amount_cents'))->toBe(1000);
});

test('the delivery margin is attributed 100% to the founder as its own role', function () {
    $taylor = User::factory()->create();
    $ben = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);
    $restaurant = Restaurant::factory()->create(['overseer_id' => $ben->id]);

    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'platform_commission_cents' => 1000,
        'delivery_margin_cents' => 36,
    ]);

    $this->resolver->record($order);
    $this->resolver->record($order); // replay must not double-write the margin

    $margin = FeeDistribution::where('order_id', $order->id)
        ->where('role', RevenueRole::DeliveryMargin->value)
        ->get();

    expect($margin)->toHaveCount(1);
    expect($margin->first()->user_id)->toBe($taylor->id);
    expect((int) $margin->first()->amount_cents)->toBe(36);
    // The margin row is SEPARATE from the founder's commission slice, so both
    // coexist under the (order, user, role) unique key.
    expect(FeeDistribution::where('order_id', $order->id)->where('user_id', $taylor->id)->count())->toBe(2);
});

test('no distributions are written when the fee rounds to zero', function () {
    $taylor = User::factory()->create();
    PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
    PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);
    $restaurant = Restaurant::factory()->create();

    $order = Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'platform_commission_cents' => 0,
    ]);

    $this->resolver->record($order);

    expect(FeeDistribution::where('order_id', $order->id)->count())->toBe(0);
});

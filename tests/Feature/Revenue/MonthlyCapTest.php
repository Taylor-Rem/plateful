<?php

use App\Models\Order;
use App\Models\Restaurant;
use App\Services\MonthlyCommissionCap;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['platform.commission_monthly_cap_cents' => 24900]);
    $this->cap = app(MonthlyCommissionCap::class);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function commissionOrder(Restaurant $r, int $commissionCents, array $overrides = []): Order
{
    return Order::factory()->create(array_merge([
        'restaurant_id' => $r->id,
        'platform_commission_cents' => $commissionCents,
        'placed_at' => now(),
    ], $overrides));
}

it('defaults a new restaurant to the platform cap', function () {
    $r = Restaurant::factory()->create();

    expect($r->commission_monthly_cap_cents)->toBe(24900);
    expect($this->cap->capFor($r))->toBe(24900);
    expect($this->cap->remainingFor($r))->toBe(24900);
});

it('grandfathers a per-restaurant cap override against a platform default change', function () {
    $r = Restaurant::factory()->create(['commission_monthly_cap_cents' => 9900]);

    // Raising the platform default must not move an existing restaurant's cap.
    config(['platform.commission_monthly_cap_cents' => 50000]);

    expect($this->cap->capFor($r))->toBe(9900);
    expect($this->cap->remainingFor($r))->toBe(9900);
});

it('reduces remaining by commission retained this month', function () {
    $r = Restaurant::factory()->create(['commission_monthly_cap_cents' => 24900]);

    commissionOrder($r, 12000);
    commissionOrder($r, 3000);

    expect($this->cap->monthToDateCents($r))->toBe(15000);
    expect($this->cap->remainingFor($r))->toBe(9900);
});

it('never returns negative remaining once the cap is exceeded', function () {
    $r = Restaurant::factory()->create(['commission_monthly_cap_cents' => 10000]);

    commissionOrder($r, 12000);

    expect($this->cap->remainingFor($r))->toBe(0);
});

it('excludes refunded orders from month-to-date', function () {
    $r = Restaurant::factory()->create(['commission_monthly_cap_cents' => 24900]);

    commissionOrder($r, 5000);
    commissionOrder($r, 4000, ['refunded_at' => now()]);

    // The refunded order's commission was reversed, so it no longer counts.
    expect($this->cap->monthToDateCents($r))->toBe(5000);
});

it('resets at the month boundary', function () {
    $r = Restaurant::factory()->create(['commission_monthly_cap_cents' => 24900]);

    // Last month's commission does not count against this month.
    commissionOrder($r, 20000, ['placed_at' => now()->subMonthNoOverflow()->startOfMonth()]);
    commissionOrder($r, 6000);

    expect($this->cap->monthToDateCents($r))->toBe(6000);
});

it('scopes month-to-date to the restaurant', function () {
    $a = Restaurant::factory()->create();
    $b = Restaurant::factory()->create();

    commissionOrder($a, 8000);
    commissionOrder($b, 3000);

    expect($this->cap->monthToDateCents($a))->toBe(8000);
    expect($this->cap->monthToDateCents($b))->toBe(3000);
});

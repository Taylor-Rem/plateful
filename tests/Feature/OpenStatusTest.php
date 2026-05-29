<?php

use App\Models\Restaurant;
use App\Models\RestaurantHour;
use Carbon\CarbonImmutable;

function osRestaurant(): Restaurant
{
    return Restaurant::create([
        'name' => 'R',
        'subdomain' => 'os'.uniqid(),
        'email' => 'os@x.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
        'timezone' => 'America/New_York',
    ]);
}

function osHour(Restaurant $r, int $dow, string $opens, string $closes): RestaurantHour
{
    return RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => $dow,
        'opens_at' => $opens,
        'closes_at' => $closes,
        'position' => 0,
    ]);
}

test('formatOpenStatus returns null when no hours configured (always open)', function () {
    $r = osRestaurant();

    expect($r->formatOpenStatus())->toBeNull();
});

test('formatOpenStatus says "Open until <close time>" when currently open', function () {
    $r = osRestaurant();
    // Monday 11:00 to 21:00
    osHour($r, 1, '11:00:00', '21:00:00');

    // Monday at 2:30 PM NY time → open until 9:00 PM
    $when = CarbonImmutable::parse('2026-06-01 14:30:00', 'America/New_York');

    expect($r->formatOpenStatus($when))->toBe('Open until 9:00 PM');
});

test('formatOpenStatus falls through to "Opens at ..." when closed', function () {
    $r = osRestaurant();
    osHour($r, 1, '11:00:00', '21:00:00');

    // Monday at 9:00 AM NY time, before open → "Opens at 11:00 AM today"
    $when = CarbonImmutable::parse('2026-06-01 09:00:00', 'America/New_York');

    expect($r->formatOpenStatus($when))->toBe('Opens at 11:00 AM today');
});

test('currentWindowClosesAt handles windows crossing midnight', function () {
    $r = osRestaurant();
    // Friday open 5pm to 2am Saturday
    osHour($r, 5, '17:00:00', '02:00:00');

    // Friday 11:30pm NY — should close at 2:00 AM Saturday
    $when = CarbonImmutable::parse('2026-06-05 23:30:00', 'America/New_York');
    $closes = $r->currentWindowClosesAt($when);

    expect($closes)->not->toBeNull()
        ->and($closes->format('Y-m-d H:i'))->toBe('2026-06-06 02:00');
});

test('formatOpenStatus inside an after-midnight tail of a previous-day window', function () {
    $r = osRestaurant();
    // Friday 5pm → 2am Saturday
    osHour($r, 5, '17:00:00', '02:00:00');

    // Saturday 1:00 AM NY — still inside Friday's window
    $when = CarbonImmutable::parse('2026-06-06 01:00:00', 'America/New_York');

    expect($r->isOpenAt($when))->toBeTrue()
        ->and($r->formatOpenStatus($when))->toBe('Open until 2:00 AM');
});

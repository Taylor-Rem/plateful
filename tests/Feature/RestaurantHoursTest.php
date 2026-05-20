<?php

use App\Models\Restaurant;
use App\Models\RestaurantHour;
use Carbon\CarbonImmutable;

function makeRestaurantWithHours(string $tz = 'America/New_York'): Restaurant
{
    return Restaurant::factory()->create(['timezone' => $tz]);
}

test('isOpenAt returns true within a normal window', function () {
    $r = makeRestaurantWithHours();
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 3, // Wednesday
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);

    // 2026-05-20 is a Wednesday.
    $when = CarbonImmutable::create(2026, 5, 20, 12, 0, 0, 'America/New_York');
    expect($r->isOpenAt($when))->toBeTrue();
});

test('isOpenAt returns false just before opens', function () {
    $r = makeRestaurantWithHours();
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 3,
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);

    $when = CarbonImmutable::create(2026, 5, 20, 8, 59, 59, 'America/New_York');
    expect($r->isOpenAt($when))->toBeFalse();
});

test('isOpenAt returns false at and after close', function () {
    $r = makeRestaurantWithHours();
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 3,
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);

    $when = CarbonImmutable::create(2026, 5, 20, 17, 0, 0, 'America/New_York');
    expect($r->isOpenAt($when))->toBeFalse();
});

test('isOpenAt handles midnight-crossing windows', function () {
    $r = makeRestaurantWithHours();
    // Wednesday (3) opens 17:00, closes 01:00 (next day Thursday=4)
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 3,
        'opens_at' => '17:00:00',
        'closes_at' => '01:00:00',
        'position' => 0,
    ]);

    // Wednesday 23:30 → open
    $a = CarbonImmutable::create(2026, 5, 20, 23, 30, 0, 'America/New_York');
    expect($r->isOpenAt($a))->toBeTrue();

    // Thursday 00:30 → open (yesterday's window crossing in)
    $b = CarbonImmutable::create(2026, 5, 21, 0, 30, 0, 'America/New_York');
    expect($r->isOpenAt($b))->toBeTrue();

    // Thursday 02:00 → closed
    $c = CarbonImmutable::create(2026, 5, 21, 2, 0, 0, 'America/New_York');
    expect($r->isOpenAt($c))->toBeFalse();
});

test('isOpenAt returns true when no hour rows exist (always-open)', function () {
    $r = makeRestaurantWithHours();
    expect($r->hours()->count())->toBe(0);
    expect($r->isOpenAt(CarbonImmutable::now()))->toBeTrue();
});

test('isOpenAt respects restaurant timezone', function () {
    $r = makeRestaurantWithHours('America/Los_Angeles');
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 3, // Wednesday
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);

    // 2026-05-20 16:00 UTC = 09:00 LA — open
    $utc = CarbonImmutable::create(2026, 5, 20, 16, 0, 0, 'UTC');
    expect($r->isOpenAt($utc))->toBeTrue();

    // 2026-05-20 15:59 UTC = 08:59 LA — closed
    $utcBefore = CarbonImmutable::create(2026, 5, 20, 15, 59, 0, 'UTC');
    expect($r->isOpenAt($utcBefore))->toBeFalse();
});

test('nextOpenAt returns the next opening time when currently closed', function () {
    $r = makeRestaurantWithHours();
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 3,
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);

    $when = CarbonImmutable::create(2026, 5, 20, 7, 0, 0, 'America/New_York');
    $next = $r->nextOpenAt($when);
    expect($next)->not->toBeNull();
    expect($next->format('Y-m-d H:i'))->toBe('2026-05-20 09:00');
});

test('nextOpenAt returns now when currently open', function () {
    $r = makeRestaurantWithHours();
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 3,
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);

    $when = CarbonImmutable::create(2026, 5, 20, 12, 0, 0, 'America/New_York');
    $next = $r->nextOpenAt($when);
    expect($next)->not->toBeNull();
    expect($next->format('Y-m-d H:i'))->toBe('2026-05-20 12:00');
});

test('formatNextOpenAt is null when open or always-open', function () {
    $r = makeRestaurantWithHours();
    // No hours → null
    expect($r->formatNextOpenAt())->toBeNull();

    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 3,
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);
    $when = CarbonImmutable::create(2026, 5, 20, 12, 0, 0, 'America/New_York');
    expect($r->formatNextOpenAt($when))->toBeNull();
});

test('formatNextOpenAt produces a human label when closed', function () {
    $r = makeRestaurantWithHours();
    RestaurantHour::create([
        'restaurant_id' => $r->id,
        'day_of_week' => 3,
        'opens_at' => '09:00:00',
        'closes_at' => '17:00:00',
        'position' => 0,
    ]);

    $when = CarbonImmutable::create(2026, 5, 20, 7, 0, 0, 'America/New_York');
    expect($r->formatNextOpenAt($when))->toBe('Opens at 9:00 AM today');
});

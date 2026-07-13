<?php

use App\Enums\RevenueRole;

test('revenue shares sum to exactly 100', function () {
    expect(array_sum(config('platform.revenue_shares')))->toBe(100);
});

test('every revenue share key is a valid RevenueRole', function () {
    foreach (array_keys((array) config('platform.revenue_shares')) as $key) {
        expect(RevenueRole::tryFrom((string) $key))->not->toBeNull();
    }
});

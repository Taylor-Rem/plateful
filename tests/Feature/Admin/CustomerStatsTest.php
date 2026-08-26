<?php

use App\Enums\OrderStatus;
use Carbon\CarbonImmutable;

require_once __DIR__.'/AdminOrderTestHelpers.php';
require_once __DIR__.'/CustomerTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('stats page is scoped to the restaurant and admin role', function () {
    $marcos = adminOrderRestaurant('marcos');
    $bobs = adminOrderRestaurant('bobs');
    $admin = adminForRestaurant($marcos);

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$marcos->subdomain}/customers/stats")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('Admin/TenantAdmin/Customers/Stats'));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$bobs->subdomain}/customers/stats")
        ->assertForbidden();

    $staff = customerUser('Staffer', 'staff@m.test');
    $staff->restaurants()->attach($marcos->id, ['role' => 'staff']);

    $this->actingAs($staff)
        ->get("http://admin.plateful.test/{$marcos->subdomain}/customers/stats")
        ->assertForbidden();
});

test('repeat rate pins order and revenue percentages', function () {
    $r = adminOrderRestaurant('marcos');
    $other = adminOrderRestaurant('bobs');
    $admin = adminForRestaurant($r);

    $alice = customerUser('Alice Apple', 'alice@example.test');
    $bob = customerUser('Bob Banana', 'bob@example.test');

    // Alice ordered at another restaurant before ever ordering here — that
    // history must not make her first order here look like a repeat.
    makeOrder($other, ['user_id' => $alice->id, 'placed_at' => now()->subDays(20), 'total_cents' => 8000]);

    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => now()->subDays(10), 'total_cents' => 1000]);
    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => now()->subDays(6), 'total_cents' => 2000]);
    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => now()->subDays(2), 'total_cents' => 3000]);
    makeOrder($r, ['user_id' => $bob->id, 'placed_at' => now()->subDays(5), 'total_cents' => 1500]);
    makeOrder($r, ['placed_at' => now()->subDays(3), 'total_cents' => 5000]);
    makeOrder($r, [
        'user_id' => $alice->id,
        'placed_at' => now()->subDay(),
        'total_cents' => 99999,
        'status' => OrderStatus::Cancelled,
    ]);

    // Identified: 4 orders / 7500 cents; repeat: Alice's 2nd + 3rd = 2 orders / 5000 cents.
    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/stats")
        ->assertInertia(fn ($p) => $p
            ->where('stats.identifiedOrders', 4)
            ->where('stats.repeatOrderPct', 50)
            ->where('stats.repeatRevenuePct', 66.7)
        );
});

test('monthly buckets split new, returning and guest revenue in the restaurant timezone', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    $alice = customerUser('Alice Apple', 'alice@example.test');

    $thisMonth = CarbonImmutable::now('America/New_York')->startOfMonth();
    $midMonth = fn (int $monthsAgo) => $thisMonth->subMonths($monthsAgo)->addDays(14)->setTime(12, 0)->utc();

    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => $midMonth(2), 'total_cents' => 1000]);
    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => $midMonth(1), 'total_cents' => 2000]);
    makeOrder($r, ['placed_at' => $midMonth(1), 'total_cents' => 500]);

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/stats")
        ->assertInertia(fn ($p) => $p
            ->has('stats.monthly', 12)
            ->where('stats.monthly.9.month', $thisMonth->subMonths(2)->format('Y-m'))
            ->where('stats.monthly.9.newCents', 1000)
            ->where('stats.monthly.9.returningCents', 0)
            ->where('stats.monthly.9.guestCents', 0)
            ->where('stats.monthly.10.newCents', 0)
            ->where('stats.monthly.10.returningCents', 2000)
            ->where('stats.monthly.10.guestCents', 500)
            ->where('stats.monthly.11.month', $thisMonth->format('Y-m'))
            ->where('stats.monthly.11.newCents', 0)
            ->where('stats.monthly.11.returningCents', 0)
            ->where('stats.monthly.11.guestCents', 0)
        );
});

test('median days between orders pins a known sequence', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    $alice = customerUser('Alice Apple', 'alice@example.test');
    $base = CarbonImmutable::parse('2026-06-01 12:00:00');

    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => $base, 'total_cents' => 1000]);
    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => $base->addDays(2), 'total_cents' => 1000]);
    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => $base->addDays(6), 'total_cents' => 1000]);

    // Gaps of 2 and 4 days — median 3.0.
    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/stats")
        ->assertInertia(fn ($p) => $p->where('stats.medianDaysBetweenOrders', 3));
});

test('median is null with fewer than two gaps', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    $alice = customerUser('Alice Apple', 'alice@example.test');
    $bob = customerUser('Bob Banana', 'bob@example.test');

    // Bob has a single order (no gap); Alice has exactly one gap — under the
    // two-pair minimum the tile still renders an em dash.
    makeOrder($r, ['user_id' => $bob->id, 'placed_at' => now()->subDays(9)]);
    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => now()->subDays(8)]);
    makeOrder($r, ['user_id' => $alice->id, 'placed_at' => now()->subDays(4)]);

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/stats")
        ->assertInertia(fn ($p) => $p->where('stats.medianDaysBetweenOrders', null));
});

test('average orders per customer covers live users with at least one order', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    customerPivot($r, customerUser('Alice Apple', 'alice@example.test'), ['total_orders' => 3]);
    customerPivot($r, customerUser('Bob Banana', 'bob@example.test'), ['total_orders' => 4]);
    customerPivot($r, customerUser('Never Ordered', 'never@example.test'), ['total_orders' => 0]);

    $deleted = customerUser('Deleted', 'deleted@example.test');
    customerPivot($r, $deleted, ['total_orders' => 100]);
    $deleted->delete();

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/stats")
        ->assertInertia(fn ($p) => $p
            ->where('stats.avgOrdersPerCustomer', 3.5)
            ->where('stats.identifiedCustomers', 3)
        );
});

test('top customers ranks by lifetime spend, capped at ten, excluding soft-deleted users', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    foreach (range(1, 11) as $i) {
        customerPivot($r, customerUser("Customer {$i}", "customer{$i}@example.test"), [
            'total_spent_cents' => $i * 1000,
        ]);
    }

    $deleted = customerUser('Big Deleted Spender', 'deleted@example.test');
    customerPivot($r, $deleted, ['total_spent_cents' => 999999]);
    $deleted->delete();

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/stats")
        ->assertInertia(fn ($p) => $p
            ->has('topCustomers', 10)
            ->where('topCustomers.0.name', 'Customer 11')
            ->where('topCustomers.0.totalSpentCents', 11000)
            ->where('topCustomers.9.name', 'Customer 2')
        );
});

test('empty restaurant returns zeros and nulls without errors', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/stats")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/TenantAdmin/Customers/Stats')
            ->where('stats.repeatOrderPct', null)
            ->where('stats.repeatRevenuePct', null)
            ->where('stats.avgOrdersPerCustomer', null)
            ->where('stats.medianDaysBetweenOrders', null)
            ->where('stats.identifiedCustomers', 0)
            ->where('stats.identifiedOrders', 0)
            ->has('stats.monthly', 12)
            ->has('topCustomers', 0)
        );
});

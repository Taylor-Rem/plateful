<?php

use App\Models\LoyaltyPoints;

require_once __DIR__.'/AdminOrderTestHelpers.php';
require_once __DIR__.'/CustomerTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('admin sees their customers with pivot counters and loyalty balance', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    $alice = customerUser('Alice Apple', 'alice@example.test');
    customerPivot($r, $alice);
    LoyaltyPoints::create(['user_id' => $alice->id, 'restaurant_id' => $r->id, 'points' => 120]);

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/TenantAdmin/Customers/Index')
            ->has('customers', 1)
            ->where('customers.0.name', 'Alice Apple')
            ->where('customers.0.email', 'alice@example.test')
            ->where('customers.0.totalOrders', 3)
            ->where('customers.0.totalSpentCents', 4500)
            ->where('customers.0.loyaltyPoints', 120)
            ->where('customers.0.marketingOptedIn', false)
            ->where('stats.totalCustomers', 1)
            ->where('stats.optedInCount', 0)
        );
});

test('customers of another restaurant never appear', function () {
    $marcos = adminOrderRestaurant('marcos');
    $bobs = adminOrderRestaurant('bobs');
    $admin = adminForRestaurant($marcos);

    customerPivot($bobs, customerUser('Bob Customer', 'bobc@example.test'));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$marcos->subdomain}/customers")
        ->assertInertia(fn ($p) => $p->has('customers', 0));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$bobs->subdomain}/customers")
        ->assertForbidden();
});

test('staff members cannot access the customers page', function () {
    $r = adminOrderRestaurant('marcos');

    $staff = customerUser('Staffer', 'staff@m.test');
    $staff->restaurants()->attach($r->id, ['role' => 'staff']);

    $this->actingAs($staff)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers")
        ->assertForbidden();
});

test('search matches name or email', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    customerPivot($r, customerUser('Alice Apple', 'alice@example.test'));
    customerPivot($r, customerUser('Bob Banana', 'bob@example.test'));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers?search=Apple")
        ->assertInertia(fn ($p) => $p->has('customers', 1)
            ->where('customers.0.name', 'Alice Apple'));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers?search=bob@example")
        ->assertInertia(fn ($p) => $p->has('customers', 1)
            ->where('customers.0.name', 'Bob Banana'));
});

test('ordered-in-last-N-days filter uses last_ordered_at', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    customerPivot($r, customerUser('Recent', 'recent@example.test'), [
        'last_ordered_at' => now()->subDays(5),
    ]);
    customerPivot($r, customerUser('Lapsed', 'lapsed@example.test'), [
        'last_ordered_at' => now()->subDays(60),
    ]);

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers?ordered=30")
        ->assertInertia(fn ($p) => $p->has('customers', 1)
            ->where('customers.0.name', 'Recent'));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers?ordered=90")
        ->assertInertia(fn ($p) => $p->has('customers', 2));
});

test('opted-in filter and count only include eligible customers', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    customerPivot($r, customerUser('Opted In', 'in@example.test'), [
        'marketing_email_opted_in_at' => now()->subDay(),
    ]);
    customerPivot($r, customerUser('Opted Out', 'out@example.test'), [
        'marketing_email_opted_in_at' => now()->subDays(2),
        'marketing_email_opted_out_at' => now()->subDay(),
    ]);
    customerPivot($r, customerUser('Never Asked', 'never@example.test'));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers?marketing=opted_in")
        ->assertInertia(fn ($p) => $p->has('customers', 1)
            ->where('customers.0.name', 'Opted In')
            ->where('customers.0.marketingOptedIn', true)
            ->where('stats.totalCustomers', 3)
            ->where('stats.optedInCount', 1));
});

test('soft-deleted users are excluded from the page and counts', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    customerPivot($r, customerUser('Kept', 'kept@example.test'));
    $deleted = customerUser('Deleted', 'deleted@example.test');
    customerPivot($r, $deleted);
    $deleted->delete();

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers")
        ->assertInertia(fn ($p) => $p->has('customers', 1)
            ->where('customers.0.name', 'Kept')
            ->where('stats.totalCustomers', 1));
});

test('sortable by lifetime spend', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    customerPivot($r, customerUser('Small Spender', 'small@example.test'), ['total_spent_cents' => 1000]);
    customerPivot($r, customerUser('Big Spender', 'big@example.test'), ['total_spent_cents' => 90000]);

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers?sort=total_spent&dir=desc")
        ->assertInertia(fn ($p) => $p->where('customers.0.name', 'Big Spender'));

    $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers?sort=total_spent&dir=asc")
        ->assertInertia(fn ($p) => $p->where('customers.0.name', 'Small Spender'));
});

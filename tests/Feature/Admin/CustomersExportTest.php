<?php

use App\Models\LoyaltyPoints;

require_once __DIR__.'/AdminOrderTestHelpers.php';
require_once __DIR__.'/CustomerTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('export streams a CSV of the customer list with consent columns', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    $alice = customerUser('Alice Apple', 'alice@example.test');
    customerPivot($r, $alice, [
        'total_orders' => 5,
        'total_spent_cents' => 12345,
        'marketing_email_opted_in_at' => now()->subDay(),
    ]);
    LoyaltyPoints::create(['user_id' => $alice->id, 'restaurant_id' => $r->id, 'points' => 80]);

    customerPivot($r, customerUser('Bob Banana', 'bob@example.test'), [
        'total_orders' => 1,
        'total_spent_cents' => 900,
    ]);

    $response = $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/export");

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload($r->subdomain.'-customers-'.now()->format('Y-m-d').'.csv');

    $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
    expect($lines)->toHaveCount(3);
    expect($lines[0])->toContain('Name,Email,Phone,"Total orders","Lifetime spend","First order","Last order","Loyalty points","Marketing opt-in","Marketing opted in at"');

    $alice = collect($lines)->first(fn ($l) => str_contains($l, 'alice@example.test'));
    expect($alice)->toContain('"Alice Apple"')
        ->toContain('123.45')
        ->toContain(',80,')
        ->toContain('yes');

    $bob = collect($lines)->first(fn ($l) => str_contains($l, 'bob@example.test'));
    expect($bob)->toContain('9.00')
        ->toContain('no');
});

test('export excludes soft-deleted users and other restaurants', function () {
    $marcos = adminOrderRestaurant('marcos');
    $bobs = adminOrderRestaurant('bobs');
    $admin = adminForRestaurant($marcos);

    customerPivot($marcos, customerUser('Kept', 'kept@example.test'));

    $deleted = customerUser('Deleted', 'deleted@example.test');
    customerPivot($marcos, $deleted);
    $deleted->delete();

    customerPivot($bobs, customerUser('Elsewhere', 'elsewhere@example.test'));

    $response = $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$marcos->subdomain}/customers/export");

    $content = $response->streamedContent();
    expect($content)->toContain('kept@example.test')
        ->not->toContain('deleted@example.test')
        ->not->toContain('elsewhere@example.test');
});

test('export respects the active filters', function () {
    $r = adminOrderRestaurant('marcos');
    $admin = adminForRestaurant($r);

    customerPivot($r, customerUser('Opted In', 'in@example.test'), [
        'marketing_email_opted_in_at' => now()->subDay(),
    ]);
    customerPivot($r, customerUser('Never Asked', 'never@example.test'));

    $content = $this->actingAs($admin)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/export?marketing=opted_in")
        ->streamedContent();

    expect($content)->toContain('in@example.test')
        ->not->toContain('never@example.test');
});

test('staff members cannot export the customer list', function () {
    $r = adminOrderRestaurant('marcos');

    $staff = customerUser('Staffer', 'staff@m.test');
    $staff->restaurants()->attach($r->id, ['role' => 'staff']);

    $this->actingAs($staff)
        ->get("http://admin.plateful.test/{$r->subdomain}/customers/export")
        ->assertForbidden();
});

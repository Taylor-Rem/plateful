<?php

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../ApiHelpers.php';
require_once __DIR__.'/EarningsTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['platform.revenue_shares' => ['founder' => 10, 'recruiter' => 0, 'overseer' => 90]]);
    CarbonImmutable::setTestNow('2026-09-14 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('the payout summary matches the super-admin page and excludes refunds', function () {
    ['taylor' => $taylor, 'ben' => $ben] = earningsFixture();

    $this->withToken(apiKeyFor(null))->getJson(API_BASE.'/operator/platform/earnings?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-09')
        ->assertJsonPath('data.monthLabel', 'September 2026')
        ->assertJsonPath('data.totalCents', 7000)
        ->assertJsonPath('data.shares.overseer', 90)
        ->assertJsonPath('data.founder.name', 'Taylor')
        ->assertJsonPath('data.operator.id', $taylor->id)
        ->assertJsonCount(2, 'data.earners')
        ->assertJsonPath('data.earners.0.name', 'Ben')
        ->assertJsonPath('data.earners.0.totalCents', 4500)
        ->assertJsonPath('data.earners.0.roles.overseer', 4500)
        ->assertJsonPath('data.earners.1.userId', $taylor->id)
        ->assertJsonPath('data.earners.1.totalCents', 2500)
        ->assertJsonPath('data.earners.1.roles.founder', 700)
        ->assertJsonPath('data.earners.1.roles.overseer', 1800);

    // Default month is the current one; August is reachable by name.
    $this->getJson(API_BASE.'/operator/platform/earnings')->assertOk()->assertJsonPath('data.month', '2026-09');
    $this->getJson(API_BASE.'/operator/platform/earnings?month=2026-08')
        ->assertOk()
        ->assertJsonPath('data.totalCents', 7000)
        ->assertJsonPath('data.earners.0.userId', $ben->id);
    $this->getJson(API_BASE.'/operator/platform/earnings?month=nope')->assertStatus(422);
});

test('the per-restaurant breakdown lists every restaurant with commission and cap', function () {
    earningsFixture();

    $response = $this->withToken(apiKeyFor(null))
        ->getJson(API_BASE.'/operator/platform/earnings/restaurants?month=2026-09')
        ->assertOk()
        ->assertJsonPath('meta.month', '2026-09')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.subdomain', 'marcos')
        ->assertJsonPath('data.0.orders', 3)
        ->assertJsonPath('data.0.refundedOrders', 1)
        ->assertJsonPath('data.0.foodSubtotalCents', 125000 + 1000)
        ->assertJsonPath('data.0.commissionCents', 5000)
        ->assertJsonPath('data.0.ledgerCents', 5000)
        ->assertJsonPath('data.0.capCents', 39900)
        ->assertJsonPath('data.0.capReached', false)
        ->assertJsonPath('data.0.capRemainingCents', 39900 - 5000)
        ->assertJsonPath('data.1.subdomain', 'luigis')
        ->assertJsonPath('data.1.commissionCents', 2000)
        ->assertJsonPath('data.1.ledgerCents', 2000);

    expect($response->json('data.0.feePercent'))->toEqual(4);

    // A past month has no "remaining" figure; a quiet restaurant is a zero row.
    $this->getJson(API_BASE.'/operator/platform/earnings/restaurants?month=2026-08')
        ->assertOk()
        ->assertJsonPath('data.0.subdomain', 'marcos')
        ->assertJsonPath('data.0.commissionCents', 7777)
        ->assertJsonPath('data.0.capRemainingCents', null)
        ->assertJsonPath('data.1.subdomain', 'luigis')
        ->assertJsonPath('data.1.orders', 0)
        ->assertJsonPath('data.1.commissionCents', 0);
});

test('the ledger filters by restaurant, person, order, role and month', function () {
    ['taylor' => $taylor, 'ben' => $ben] = earningsFixture();

    $this->withToken(apiKeyFor(null));

    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?month=2026-09')
        ->assertOk()
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.totalCents', 7000)
        ->assertJsonPath('data.0.orderNumber', 'LUI-00002')
        ->assertJsonPath('data.0.restaurantSubdomain', 'luigis')
        ->assertJsonPath('data.0.userName', 'Taylor')
        ->assertJsonPath('data.0.role', 'overseer')
        ->assertJsonPath('data.0.orderRefunded', false);

    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?month=2026-09&include_refunded=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 5);

    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?restaurant=marcos&month=2026-09')
        ->assertOk()->assertJsonPath('meta.total', 2);

    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?user=ben@example.test')
        ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.userId', $ben->id);

    $this->getJson(API_BASE."/operator/platform/earnings/ledger?user={$taylor->id}&role=founder")
        ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('meta.totalCents', 700);

    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?order=MAR-00001')
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.amountCents', 4500);

    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?from=2026-09-04&to=2026-09-06')
        ->assertOk()->assertJsonPath('meta.total', 2);

    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?restaurant=nowhere')->assertStatus(422);
    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?role=ceo')->assertStatus(422);
});

test('the ledger still names a restaurant and an earner that were later deleted', function () {
    ['ben' => $ben, 'luigis' => $luigis] = earningsFixture();
    $luigis->delete();
    $ben->delete();

    $this->withToken(apiKeyFor(null))
        ->getJson(API_BASE.'/operator/platform/earnings/ledger?month=2026-09&restaurant=luigis')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);

    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?order=LUI-00001')
        ->assertOk()
        ->assertJsonPath('data.0.restaurantName', 'Luigis')
        ->assertJsonPath('data.0.restaurantSubdomain', 'luigis');

    $this->getJson(API_BASE.'/operator/platform/earnings/ledger?order=MAR-00001')
        ->assertOk()
        ->assertJsonPath('data.0.userName', 'Ben')
        ->assertJsonPath('data.0.userEmail', 'ben@example.test');

    $this->getJson(API_BASE.'/operator/platform/earnings?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data.earners.0.name', 'Ben')
        ->assertJsonPath('data.earners.0.userId', $ben->id);
});

test('platform reports are gated to platform actors holding platform:read', function () {
    ['marcos' => $marcos] = earningsFixture();
    $admin = adminForRestaurant($marcos);
    $super = User::factory()->superAdmin()->create();

    $url = API_BASE.'/operator/platform/earnings?month=2026-09';

    // A restaurant key with every restaurant scope: still a 403.
    $this->withToken(apiKeyFor($marcos))->getJson($url)->assertForbidden();
    forgetApiGuards();

    // A restaurant admin's token: 403.
    $this->withToken(operatorTokenFor($admin))->getJson($url)->assertForbidden();
    forgetApiGuards();

    // A platform key limited to orders:read: 403.
    $this->withToken(apiKeyFor(null, [ApiKeyScope::OrdersRead]))->getJson($url)->assertForbidden();
    forgetApiGuards();

    // A read-only platform key: allowed, and cannot write anywhere.
    $this->withToken(apiKeyFor(null, [ApiKeyScope::PlatformRead]))->getJson($url)->assertOk();
    $this->postJson(operatorUrl($marcos, 'orders/MAR-00001/transition'), ['to_status' => 'confirmed'])->assertForbidden();
    forgetApiGuards();

    // A super admin's token: allowed.
    $this->withToken(operatorTokenFor($super))->getJson($url)->assertOk();
});

test('api-key:create can mint a read-only platform key but never a platform scope on a restaurant key', function () {
    Restaurant::factory()->create(['subdomain' => 'marcos']);

    $this->artisan('api-key:create', ['name' => 'Reports', '--platform' => true, '--scopes' => ['platform:read']])
        ->assertSuccessful();
    $this->artisan('api-key:create', ['name' => 'x', '--restaurant' => 'marcos', '--scopes' => ['platform:read']])
        ->assertFailed();

    expect(ApiKey::query()->count())->toBe(1)
        ->and(ApiKey::query()->first()->scopes)->toBe(['platform:read']);

    // The REST key endpoint refuses it too.
    $this->withToken(operatorTokenFor(User::factory()->superAdmin()->create()))
        ->postJson(operatorUrl(Restaurant::query()->firstOrFail(), 'api-keys'), ['name' => 'x', 'scopes' => ['platform:read']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scopes.0']);
});

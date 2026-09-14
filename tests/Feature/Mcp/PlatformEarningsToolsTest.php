<?php

use App\Enums\ApiKeyScope;
use App\Mcp\Servers\PlatformServer;
use App\Mcp\Tools\EarningsByRestaurant;
use App\Mcp\Tools\EarningsLedger;
use App\Mcp\Tools\EarningsSummary;
use App\Models\ApiKey;
use Carbon\CarbonImmutable;

require_once __DIR__.'/../Api/V1/ApiHelpers.php';
require_once __DIR__.'/../Api/V1/Operator/EarningsTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['platform.revenue_shares' => ['founder' => 10, 'recruiter' => 0, 'overseer' => 90]]);
    CarbonImmutable::setTestNow('2026-09-14 12:00:00');
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('earnings tools answer for a platform key', function () {
    ['ben' => $ben] = earningsFixture();
    $platform = ApiKey::factory()->platform()->create();

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(EarningsSummary::class, ['month' => '2026-09'])
        ->assertOk()
        ->assertSee('"totalCents":7000')
        ->assertSee('"name":"Ben"');

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(EarningsByRestaurant::class, ['month' => '2026-09'])
        ->assertOk()
        ->assertSee('"subdomain":"marcos"')
        ->assertSee('"totalCommissionCents":7000')
        ->assertSee('"capRemainingCents":34900');

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(EarningsLedger::class, ['user' => 'ben@example.test', 'month' => '2026-09'])
        ->assertOk()
        ->assertSee('MAR-00001')
        ->assertDontSee('MAR-REFND')
        ->assertSee('"total":1');

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(EarningsLedger::class, ['restaurant' => 'nowhere'])
        ->assertHasErrors(['No restaurant [nowhere]. Call list-restaurants for subdomains.']);

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(EarningsSummary::class, ['month' => 'September'])
        ->assertHasErrors();
});

test('earnings tools refuse restaurant keys and platform keys without platform:read', function () {
    ['marcos' => $marcos] = earningsFixture();

    PlatformServer::actingAs(ApiKey::factory()->for($marcos)->create(), 'api-key')
        ->tool(EarningsSummary::class)
        ->assertHasErrors(['This credential lacks the platform:read scope; platform reports need a platform key or a super admin.']);

    PlatformServer::actingAs(ApiKey::factory()->platform()->scopes([ApiKeyScope::OrdersRead])->create(), 'api-key')
        ->tool(EarningsByRestaurant::class)
        ->assertHasErrors();

    PlatformServer::actingAs(ApiKey::factory()->platform()->scopes([ApiKeyScope::PlatformRead])->create(), 'api-key')
        ->tool(EarningsSummary::class)
        ->assertOk();
});

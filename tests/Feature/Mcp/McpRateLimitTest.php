<?php

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\Restaurant;

require_once __DIR__.'/../Api/V1/ApiHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

/**
 * @return array<string, mixed>
 */
function mcpToolsListBody(int $id = 1): array
{
    return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/list', 'params' => []];
}

test('a key with its own ceiling is throttled at it', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    ['plainTextKey' => $plain] = ApiKey::mint('Claude', [ApiKeyScope::MenuRead], $restaurant, rateLimitPerMinute: 2);
    $headers = ['Accept' => 'application/json, text/event-stream'];

    $this->withToken($plain)->postJson('http://plateful.test/mcp/platform', mcpToolsListBody(1), $headers)
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', '2');
    $this->withToken($plain)->postJson('http://plateful.test/mcp/platform', mcpToolsListBody(2), $headers)
        ->assertOk()
        ->assertHeader('X-RateLimit-Remaining', '0');
    $this->withToken($plain)->postJson('http://plateful.test/mcp/platform', mcpToolsListBody(3), $headers)
        ->assertStatus(429);

    // The REST side shares the bucket.
    $this->withToken($plain)->getJson(operatorUrl($restaurant, 'menu'))->assertStatus(429);
});

test('a key without a ceiling gets the platform default', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    ['plainTextKey' => $plain] = ApiKey::mint('Claude', [ApiKeyScope::MenuRead], $restaurant);

    $this->withToken($plain)
        ->postJson('http://plateful.test/mcp/platform', mcpToolsListBody(), ['Accept' => 'application/json, text/event-stream'])
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', (string) ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE);
});

test('the REST key endpoint and the console command accept a rate limit', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $manager = apiKeyFor($restaurant, [ApiKeyScope::ApiKeysManage]);

    $this->withToken($manager)
        ->postJson(operatorUrl($restaurant, 'api-keys'), ['name' => 'Tablet', 'scopes' => ['orders:read'], 'rate_limit_per_minute' => 30])
        ->assertCreated()
        ->assertJsonPath('data.key.rateLimitPerMinute', 30);

    $this->withToken($manager)
        ->postJson(operatorUrl($restaurant, 'api-keys'), ['name' => 'Tablet', 'scopes' => ['orders:read']])
        ->assertCreated()
        ->assertJsonPath('data.key.rateLimitPerMinute', ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE);

    $this->withToken($manager)
        ->postJson(operatorUrl($restaurant, 'api-keys'), ['name' => 'Tablet', 'scopes' => ['orders:read'], 'rate_limit_per_minute' => 1000])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rate_limit_per_minute']);

    $this->artisan('api-key:create', ['name' => 'Kiosk', '--restaurant' => 'marcos', '--rate-limit' => 10])
        ->assertSuccessful();
    $this->artisan('api-key:create', ['name' => 'Kiosk', '--restaurant' => 'marcos', '--rate-limit' => 0])
        ->assertFailed();

    expect(ApiKey::query()->where('name', 'Kiosk')->sole()->rate_limit_per_minute)->toBe(10);
});

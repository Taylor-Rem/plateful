<?php

use App\Enums\ApiKeyScope;
use App\Models\ApiCallLog;
use App\Models\ApiKey;
use App\Models\Restaurant;

require_once __DIR__.'/../Api/V1/ApiHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

const MCP_CONNECT_HEADERS = ['Accept' => 'application/json, text/event-stream'];

/**
 * @return array<string, mixed>
 */
function mcpConnectInitialize(): array
{
    return [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'claude.ai', 'version' => '1']],
    ];
}

test('the connector URL authenticates a key without a header', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint('Claude', [ApiKeyScope::MenuRead], $restaurant);
    $url = "http://plateful.test/mcp/platform/{$plain}";

    expect(route('mcp.platform.connect', ['connectKey' => $plain]))->toBe($url);

    $this->postJson($url, mcpConnectInitialize(), MCP_CONNECT_HEADERS)
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Plateful');

    $this->postJson($url, [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'get-menu', 'arguments' => ['restaurant' => 'marcos']],
    ], MCP_CONNECT_HEADERS)
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    expect(ApiCallLog::query()->sole()->api_key_id)->toBe($key->id);
});

test('a revoked, unknown or malformed key in the URL is refused', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint('Claude', [ApiKeyScope::MenuRead], $restaurant);
    $key->revoke();

    $this->postJson("http://plateful.test/mcp/platform/{$plain}", mcpConnectInitialize(), MCP_CONNECT_HEADERS)
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');

    $this->postJson('http://plateful.test/mcp/platform/pfk_test_'.str_repeat('z', 40), mcpConnectInitialize(), MCP_CONNECT_HEADERS)
        ->assertUnauthorized();

    $this->postJson('http://plateful.test/mcp/platform/not-a-key', mcpConnectInitialize(), MCP_CONNECT_HEADERS)
        ->assertNotFound();

    expect(ApiCallLog::query()->count())->toBe(0);
});

test('a header still wins over the path on the connector URL', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    ['plainTextKey' => $inPath] = ApiKey::mint('Path', [ApiKeyScope::MenuRead], $restaurant);
    ['key' => $headerKey, 'plainTextKey' => $inHeader] = ApiKey::mint('Header', [ApiKeyScope::MenuRead], $restaurant);

    $this->withToken($inHeader)->postJson("http://plateful.test/mcp/platform/{$inPath}", [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => 'get-menu', 'arguments' => ['restaurant' => 'marcos']],
    ], MCP_CONNECT_HEADERS)->assertOk();

    expect(ApiCallLog::query()->sole()->api_key_id)->toBe($headerKey->id);
});

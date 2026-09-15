<?php

use App\Enums\ApiKeyScope;
use App\Mcp\Servers\PlatformServer;
use App\Mcp\Tools\GetMenu;
use App\Mcp\Tools\ListOrders;
use App\Mcp\Tools\ListRestaurants;
use App\Mcp\Tools\SetMenuItemAvailability;
use App\Mcp\Tools\UploadImage;
use App\Models\ApiCallLog;
use App\Models\ApiKey;
use App\Models\Restaurant;
use App\Support\Api\ApiCallLogger;

require_once __DIR__.'/../Api/V1/ApiHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

const MCP_AUDIT_HEADERS = ['Accept' => 'application/json, text/event-stream'];

test('a tool call is logged with the key, the restaurant, its arguments and the outcome', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $key = ApiKey::factory()->for($restaurant)->create();

    PlatformServer::actingAs($key, 'api-key')
        ->tool(GetMenu::class, ['restaurant' => 'marcos'])
        ->assertOk();

    $log = ApiCallLog::query()->sole();

    expect($log->api_key_id)->toBe($key->id)
        ->and($log->user_id)->toBeNull()
        ->and($log->restaurant_id)->toBe($restaurant->id)
        ->and($log->channel)->toBe(ApiCallLog::CHANNEL_MCP)
        ->and($log->action)->toBe('get-menu')
        ->and($log->arguments)->toBe(['restaurant' => 'marcos'])
        ->and($log->ok)->toBeTrue()
        ->and($log->error)->toBeNull()
        ->and($log->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and($log->created_at)->not->toBeNull();
});

test('refused calls are logged as failed, with the reason, at the key\'s own restaurant', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    Restaurant::factory()->create(['subdomain' => 'luigis']);
    $readOnly = ApiKey::factory()->for($restaurant)->scopes([ApiKeyScope::OrdersRead])->create();

    PlatformServer::actingAs($readOnly, 'api-key')
        ->tool(SetMenuItemAvailability::class, ['restaurant' => 'marcos', 'menu_item_id' => 1, 'is_available' => false])
        ->assertHasErrors();

    PlatformServer::actingAs($readOnly, 'api-key')
        ->tool(ListOrders::class, ['restaurant' => 'luigis'])
        ->assertHasErrors();

    $logs = ApiCallLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->action)->toBe('set-menu-item-availability')
        ->and($logs[0]->ok)->toBeFalse()
        ->and($logs[0]->error)->toContain('lacks the menu:write scope')
        ->and($logs[0]->restaurant_id)->toBe($restaurant->id)
        ->and($logs[1]->action)->toBe('list-orders')
        ->and($logs[1]->ok)->toBeFalse()
        ->and($logs[1]->error)->toContain('No restaurant [luigis]')
        // A restaurant key's calls file under its restaurant even when it
        // named another one, so the owner sees the attempt.
        ->and($logs[1]->restaurant_id)->toBe($restaurant->id);
});

test('a platform key\'s calls file under the restaurant it named, or none', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $platform = ApiKey::factory()->platform()->create();

    PlatformServer::actingAs($platform, 'api-key')->tool(ListRestaurants::class)->assertOk();
    PlatformServer::actingAs($platform, 'api-key')->tool(GetMenu::class, ['restaurant' => 'marcos'])->assertOk();

    $logs = ApiCallLog::query()->orderBy('id')->get();

    expect($logs[0]->action)->toBe('list-restaurants')
        ->and($logs[0]->restaurant_id)->toBeNull()
        ->and($logs[1]->action)->toBe('get-menu')
        ->and($logs[1]->restaurant_id)->toBe($restaurant->id);
});

test('image payloads and long values are redacted from the log', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $key = ApiKey::factory()->for($restaurant)->create();

    PlatformServer::actingAs($key, 'api-key')
        ->tool(UploadImage::class, [
            'restaurant' => 'marcos',
            'target' => 'gallery',
            'image_base64' => str_repeat('A', 5000),
            'caption' => str_repeat('x', 300),
        ]);

    $log = ApiCallLog::query()->sole();

    expect($log->action)->toBe('upload-image')
        ->and($log->arguments['image_base64'])->toBe('[omitted, 5000 bytes]')
        ->and(strlen($log->arguments['caption']))->toBeLessThan(260)
        ->and($log->arguments['caption'])->toEndWith('[300 chars]')
        ->and($log->arguments['target'])->toBe('gallery');

    expect(ApiCallLogger::redact(['source_url' => 'https://x.test/a.jpg', 'nested' => ['image_base64' => 'zz']]))
        ->toBe(['source_url' => '[omitted, 20 bytes]', 'nested' => ['image_base64' => '[omitted, 2 bytes]']]);
});

test('calls over HTTP are logged for the key that signed them', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint('Claude', [ApiKeyScope::MenuRead], $restaurant);

    $this->withToken($plain)->postJson('http://plateful.test/mcp/platform', [
        'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
        'params' => ['name' => 'get-menu', 'arguments' => ['restaurant' => 'marcos']],
    ], MCP_AUDIT_HEADERS)
        ->assertOk()
        ->assertJsonPath('result.isError', false);

    // tools/list is not a call and is not logged.
    $this->withToken($plain)->postJson('http://plateful.test/mcp/platform', [
        'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/list', 'params' => [],
    ], MCP_AUDIT_HEADERS)->assertOk();

    $log = ApiCallLog::query()->sole();

    expect($log->api_key_id)->toBe($key->id)
        ->and($log->restaurant_id)->toBe($restaurant->id)
        ->and($log->action)->toBe('get-menu')
        ->and($log->ok)->toBeTrue();
});

test('operator REST requests are logged by route name, refusals included', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint('Claude', [ApiKeyScope::OrdersRead], $restaurant);

    $this->withToken($plain)->getJson(operatorUrl($restaurant, 'orders'))->assertOk();
    $this->withToken($plain)->getJson(operatorUrl($restaurant, 'menu'))->assertForbidden();
    $this->withToken($plain)->getJson(API_BASE.'/operator/me')->assertOk();
    // Strangers never reach the log.
    forgetApiGuards();
    $this->getJson(operatorUrl($restaurant, 'orders'))->assertUnauthorized();

    $logs = ApiCallLog::query()->orderBy('id')->get();

    expect($logs)->toHaveCount(3)
        ->and($logs->pluck('channel')->unique()->all())->toBe([ApiCallLog::CHANNEL_REST])
        ->and($logs->pluck('api_key_id')->unique()->all())->toBe([$key->id])
        ->and($logs[0]->action)->toBe('restaurants.orders.index')
        ->and($logs[0]->restaurant_id)->toBe($restaurant->id)
        ->and($logs[0]->ok)->toBeTrue()
        ->and($logs[1]->action)->toBe('restaurants.menu')
        ->and($logs[1]->ok)->toBeFalse()
        ->and($logs[1]->error)->toBe('This credential lacks the menu:read scope.')
        ->and($logs[1]->restaurant_id)->toBe($restaurant->id)
        ->and($logs[2]->action)->toBe('me')
        ->and($logs[2]->restaurant_id)->toBeNull();
});

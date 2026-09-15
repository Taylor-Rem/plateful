<?php

use App\Enums\ApiKeyScope;
use App\Enums\RestaurantRole;
use App\Models\ApiCallLog;
use App\Models\ApiKey;
use App\Models\Restaurant;
use App\Models\User;

require_once __DIR__.'/../Api/V1/ApiHelpers.php';

const AI_ADMIN_HOST = 'http://admin.plateful.test';

/**
 * @return array{0: User, 1: Restaurant}
 */
function aiPageOwnerAndRestaurant(): array
{
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    return [$owner, $restaurant];
}

function aiPageUrl(Restaurant $restaurant, string $path = ''): string
{
    return AI_ADMIN_HOST."/{$restaurant->subdomain}/settings/ai".$path;
}

/**
 * @return array<string, mixed>
 */
function mcpInitializeBody(): array
{
    return [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ];
}

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

it('shows keys, permissions, the MCP URL and recent calls to a restaurant admin', function () {
    [$owner, $restaurant] = aiPageOwnerAndRestaurant();
    $key = ApiKey::factory()->for($restaurant)->create(['name' => 'Claude', 'rate_limit_per_minute' => 60]);
    ApiKey::factory()->for($restaurant)->revoked()->create(['name' => 'Old']);
    ApiCallLog::factory()->for($key, 'apiKey')->for($restaurant)->create(['action' => 'get-menu']);
    ApiCallLog::factory()->for($key, 'apiKey')->for($restaurant)->failed()->create(['action' => 'set-menu-item-availability']);
    // Another restaurant's activity never shows here.
    ApiCallLog::factory()->create(['action' => 'list-orders']);

    $this->actingAs($owner)
        ->get(aiPageUrl($restaurant))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/TenantAdmin/AiAssistant')
            ->has('keys', 2)
            ->where('keys.0.name', 'Old')
            ->where('keys.1.name', 'Claude')
            ->where('keys.1.rateLimitPerMinute', 60)
            ->where('keys.0.rateLimitPerMinute', ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE)
            ->has('calls', 2)
            ->where('calls.0.action', 'set-menu-item-availability')
            ->where('calls.0.ok', false)
            ->where('calls.0.keyName', 'Claude')
            ->where('calls.1.action', 'get-menu')
            ->where('mcpUrl', 'http://plateful.test/mcp/platform')
            ->where('keysPath', '/marcos/settings/ai/keys')
            ->where('defaultRateLimit', 60)
            ->where('maxRateLimit', ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE)
            ->where('createdKey', null)
            ->where('scopes', function ($scopes) {
                $values = collect($scopes)->pluck('value');

                return $values->contains('menu:write')
                    && ! $values->contains('*')
                    && ! $values->contains('platform:read')
                    && collect($scopes)->firstWhere('value', 'menu:write')['recommended'] === true
                    && collect($scopes)->firstWhere('value', 'orders:write')['recommended'] === false;
            })
        );
});

it('is forbidden for staff', function () {
    [, $restaurant] = aiPageOwnerAndRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)->get(aiPageUrl($restaurant))->assertForbidden();
    $this->actingAs($staff)->post(aiPageUrl($restaurant, '/keys'), ['name' => 'x'])->assertForbidden();
});

it('mints a key with the chosen permissions and rate limit, shows it once, and the key works', function () {
    [$owner, $restaurant] = aiPageOwnerAndRestaurant();

    $this->actingAs($owner)
        ->from(aiPageUrl($restaurant))
        ->post(aiPageUrl($restaurant, '/keys'), [
            'name' => 'Claude',
            'scopes' => ['menu:read', 'menu:write'],
            'rate_limit_per_minute' => 45,
        ])
        ->assertRedirect(aiPageUrl($restaurant))
        ->assertSessionHas('createdKey');

    $created = session('createdKey');
    $plain = $created['plainTextKey'];

    expect($plain)->toStartWith('pfk_test_')
        ->and(strlen($plain))->toBe(49)
        ->and($created['mcpUrl'])->toBe('http://plateful.test/mcp/platform')
        ->and($created['connectUrl'])->toBe('http://plateful.test/mcp/platform/'.$plain)
        ->and($created['key']['name'])->toBe('Claude')
        ->and($created['key']['rateLimitPerMinute'])->toBe(45)
        ->and($created['key']['createdByName'])->toBe($owner->name);

    $key = ApiKey::query()->sole();
    expect($key->restaurant_id)->toBe($restaurant->id)
        ->and($key->scopes)->toBe(['menu:read', 'menu:write'])
        ->and($key->rate_limit_per_minute)->toBe(45)
        ->and($key->created_by_user_id)->toBe($owner->id)
        ->and($key->key_hash)->toBe(ApiKey::hashKey($plain));

    // The flash survives exactly one page load.
    $this->actingAs($owner)->get(aiPageUrl($restaurant))
        ->assertInertia(fn ($page) => $page->where('createdKey.plainTextKey', $plain));
    $this->actingAs($owner)->get(aiPageUrl($restaurant))
        ->assertInertia(fn ($page) => $page->where('createdKey', null));

    // And the key signs MCP requests.
    $this->withToken($plain)
        ->postJson('http://plateful.test/mcp/platform', mcpInitializeBody(), ['Accept' => 'application/json, text/event-stream'])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Plateful');
});

it('refuses platform-only permissions, no permissions and a rate limit above the ceiling', function () {
    [$owner, $restaurant] = aiPageOwnerAndRestaurant();

    $this->actingAs($owner)
        ->from(aiPageUrl($restaurant))
        ->post(aiPageUrl($restaurant, '/keys'), ['name' => 'Bad', 'scopes' => ['*'], 'rate_limit_per_minute' => 10])
        ->assertSessionHasErrors(['scopes.0']);

    $this->actingAs($owner)
        ->from(aiPageUrl($restaurant))
        ->post(aiPageUrl($restaurant, '/keys'), ['name' => 'Bad', 'scopes' => ['platform:read'], 'rate_limit_per_minute' => 10])
        ->assertSessionHasErrors(['scopes.0']);

    $this->actingAs($owner)
        ->from(aiPageUrl($restaurant))
        ->post(aiPageUrl($restaurant, '/keys'), ['name' => 'Bad', 'scopes' => [], 'rate_limit_per_minute' => 10])
        ->assertSessionHasErrors(['scopes' => 'Pick at least one permission.']);

    $this->actingAs($owner)
        ->from(aiPageUrl($restaurant))
        ->post(aiPageUrl($restaurant, '/keys'), ['name' => 'Bad', 'scopes' => ['menu:read'], 'rate_limit_per_minute' => ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE + 1])
        ->assertSessionHasErrors(['rate_limit_per_minute']);

    $this->actingAs($owner)
        ->from(aiPageUrl($restaurant))
        ->post(aiPageUrl($restaurant, '/keys'), ['name' => 'Bad', 'scopes' => ['menu:read']])
        ->assertSessionHasErrors(['rate_limit_per_minute']);

    expect(ApiKey::query()->count())->toBe(0);
});

it('revokes a key and the revoked key is refused on the next call', function () {
    [$owner, $restaurant] = aiPageOwnerAndRestaurant();
    ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint('Claude', [ApiKeyScope::MenuRead], $restaurant);
    $headers = ['Accept' => 'application/json, text/event-stream'];

    $this->withToken($plain)->postJson('http://plateful.test/mcp/platform', mcpInitializeBody(), $headers)->assertOk();
    forgetApiGuards();

    $this->actingAs($owner)
        ->from(aiPageUrl($restaurant))
        ->delete(aiPageUrl($restaurant, "/keys/{$key->id}"))
        ->assertRedirect(aiPageUrl($restaurant))
        ->assertSessionHas('success');

    expect($key->fresh()->revoked_at)->not->toBeNull();
    forgetApiGuards();

    $this->withToken($plain)->postJson('http://plateful.test/mcp/platform', mcpInitializeBody(), $headers)->assertUnauthorized();
});

it('cannot revoke another restaurant\'s key', function () {
    [$owner, $restaurant] = aiPageOwnerAndRestaurant();
    $other = ApiKey::factory()->create();

    $this->actingAs($owner)->delete(aiPageUrl($restaurant, "/keys/{$other->id}"))->assertNotFound();

    expect($other->fresh()->revoked_at)->toBeNull();
});

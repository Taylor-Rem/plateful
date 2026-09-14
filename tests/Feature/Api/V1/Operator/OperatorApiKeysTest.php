<?php

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\Restaurant;

require_once __DIR__.'/../ApiHelpers.php';
require_once __DIR__.'/../../../Admin/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

test('an admin mints a restaurant key, sees the secret once, and it works', function () {
    $restaurant = adminOrderRestaurant();
    $admin = adminForRestaurant($restaurant);

    $response = $this->withToken(operatorTokenFor($admin))
        ->postJson(operatorUrl($restaurant, 'api-keys'), [
            'name' => 'POS bridge',
            'scopes' => ['orders:read', 'orders:write', 'orders:read'],
            'expires_at' => now()->addYear()->toDateString(),
        ])
        ->assertCreated()
        ->assertJsonPath('data.key.name', 'POS bridge')
        ->assertJsonPath('data.key.scopes', ['orders:read', 'orders:write'])
        ->assertJsonPath('data.key.isPlatform', false)
        ->assertJsonPath('data.key.createdByName', 'Owner');

    $plain = $response->json('data.plainTextKey');
    expect($plain)->toStartWith('pfk_test_');

    $key = ApiKey::query()->firstOrFail();
    expect($key->restaurant_id)->toBe($restaurant->id)
        ->and($key->key_hash)->toBe(hash('sha256', $plain))
        ->and($key->expires_at)->not->toBeNull();

    $this->getJson(operatorUrl($restaurant, 'api-keys'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.keyPrefix', substr($plain, 0, 17))
        ->assertJsonMissing(['plainTextKey' => $plain]);
    forgetApiGuards();

    $this->withToken($plain)->getJson(operatorUrl($restaurant, 'orders'))->assertOk();
});

test('the platform wildcard and unknown scopes are rejected for restaurant keys', function () {
    $restaurant = adminOrderRestaurant();
    $admin = adminForRestaurant($restaurant);

    $this->withToken(operatorTokenFor($admin));

    $this->postJson(operatorUrl($restaurant, 'api-keys'), ['name' => 'x', 'scopes' => ['*']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scopes.0']);

    $this->postJson(operatorUrl($restaurant, 'api-keys'), ['name' => 'x', 'scopes' => ['everything']])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scopes.0']);

    $this->postJson(operatorUrl($restaurant, 'api-keys'), ['name' => 'x', 'scopes' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['scopes']);

    expect(ApiKey::query()->count())->toBe(0);
});

test('revoking a key stops it immediately and only this restaurant\'s keys are reachable', function () {
    $restaurant = adminOrderRestaurant();
    $admin = adminForRestaurant($restaurant);
    ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint('k', ApiKeyScope::restaurantScopes(), $restaurant);
    $foreign = ApiKey::factory()->for(Restaurant::factory())->create();

    $this->withToken(operatorTokenFor($admin));

    $this->deleteJson(operatorUrl($restaurant, "api-keys/{$foreign->id}"))->assertNotFound();
    $this->deleteJson(operatorUrl($restaurant, "api-keys/{$key->id}"))->assertNoContent();

    expect($key->fresh()->isRevoked())->toBeTrue()
        ->and($foreign->fresh()->isRevoked())->toBeFalse();

    $this->getJson(operatorUrl($restaurant, 'api-keys'))
        ->assertOk()
        ->assertJsonPath('data.0.revokedAt', fn ($value) => $value !== null);
    forgetApiGuards();

    $this->withToken($plain)->getJson(operatorUrl($restaurant, 'orders'))->assertUnauthorized();
});

test('a key holding api-keys:manage can mint keys for its own restaurant only', function () {
    $restaurant = adminOrderRestaurant();
    $other = Restaurant::factory()->create();

    $this->withToken(apiKeyFor($restaurant, [ApiKeyScope::ApiKeysManage]));

    $this->postJson(operatorUrl($restaurant, 'api-keys'), ['name' => 'child', 'scopes' => ['menu:read']])
        ->assertCreated()
        ->assertJsonPath('data.key.createdByName', null);

    $this->postJson(operatorUrl($other, 'api-keys'), ['name' => 'child', 'scopes' => ['menu:read']])
        ->assertNotFound();

    expect(ApiKey::query()->where('restaurant_id', $other->id)->count())->toBe(0);
});

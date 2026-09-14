<?php

use App\Enums\ApiKeyScope;
use App\Models\ApiKey;
use App\Models\Restaurant;
use App\Models\User;

require_once __DIR__.'/../ApiHelpers.php';
require_once __DIR__.'/../../../Admin/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

describe('API keys', function () {
    test('a platform key operates every restaurant, whatever its state', function () {
        Restaurant::factory()->create(['subdomain' => 'live', 'name' => 'A Live']);
        Restaurant::factory()->approved()->create(['subdomain' => 'onboarding', 'name' => 'B Onboarding']);
        Restaurant::factory()->suspended()->create(['subdomain' => 'suspended', 'name' => 'C Suspended']);

        $this->withToken(apiKeyFor(null, name: 'Claude'))->getJson(API_BASE.'/operator/me')
            ->assertOk()
            ->assertJsonPath('data.type', 'api_key')
            ->assertJsonPath('data.name', 'Claude')
            ->assertJsonPath('data.isPlatform', true)
            ->assertJsonPath('data.scopes', ['*'])
            ->assertJsonCount(3, 'data.restaurants')
            ->assertJsonPath('data.restaurants.*.subdomain', ['live', 'onboarding', 'suspended'])
            ->assertJsonPath('data.restaurants.0.role', 'admin');

        $this->getJson(operatorUrl(Restaurant::query()->where('subdomain', 'suspended')->firstOrFail()))
            ->assertOk()
            ->assertJsonPath('data.subdomain', 'suspended');
    });

    test('a restaurant key sees its restaurant only and gets a 404 elsewhere', function () {
        $mine = Restaurant::factory()->approved()->create(['subdomain' => 'mine']);
        $other = Restaurant::factory()->create(['subdomain' => 'other']);

        $this->withToken(apiKeyFor($mine))->getJson(API_BASE.'/operator/restaurants')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subdomain', 'mine')
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.isLive', false)
            ->assertJsonPath('data.0.role', null)
            ->assertJsonPath('data.0.scopes', array_map(fn (ApiKeyScope $s) => $s->value, ApiKeyScope::restaurantScopes()));

        $this->getJson(operatorUrl($mine))->assertOk();
        $this->getJson(operatorUrl($other))->assertNotFound();
        $this->getJson(operatorUrl($other, 'orders'))->assertNotFound();
    });

    test('a suspended restaurant is off for its own key', function () {
        $restaurant = Restaurant::factory()->suspended()->create();

        $this->withToken(apiKeyFor($restaurant))->getJson(API_BASE.'/operator/me')
            ->assertOk()
            ->assertJsonCount(0, 'data.restaurants');

        $this->getJson(operatorUrl($restaurant))->assertNotFound();
    });

    test('revoked, expired, unknown and missing keys are unauthenticated', function () {
        $restaurant = Restaurant::factory()->create();

        $revoked = ApiKey::mint('r', ApiKeyScope::restaurantScopes(), $restaurant);
        $revoked['key']->revoke();
        $this->withToken($revoked['plainTextKey'])->getJson(API_BASE.'/operator/me')->assertUnauthorized();
        forgetApiGuards();

        $expired = ApiKey::mint('e', ApiKeyScope::restaurantScopes(), $restaurant, expiresAt: now()->subMinute());
        $this->withToken($expired['plainTextKey'])->getJson(API_BASE.'/operator/me')->assertUnauthorized();
        forgetApiGuards();

        $this->withToken('pfk_test_'.str_repeat('x', 40))->getJson(API_BASE.'/operator/me')->assertUnauthorized();
        forgetApiGuards();

        $this->getJson(API_BASE.'/operator/me')->assertUnauthorized()->assertJsonStructure(['message']);
    });

    test('scopes gate each route and a missing scope is a 403', function () {
        $restaurant = adminOrderRestaurant();
        $order = makeOrder($restaurant);

        $this->withToken(apiKeyFor($restaurant, [ApiKeyScope::OrdersRead]));

        $this->getJson(operatorUrl($restaurant, 'orders'))->assertOk();
        $this->getJson(operatorUrl($restaurant, "orders/{$order->number}"))->assertOk();
        $this->postJson(operatorUrl($restaurant, "orders/{$order->number}/transition"), ['to_status' => 'confirmed'])
            ->assertForbidden()
            ->assertJsonPath('message', 'This credential lacks the orders:write scope.');
        $this->getJson(operatorUrl($restaurant, 'customers'))->assertForbidden();
        $this->getJson(operatorUrl($restaurant, 'menu'))->assertForbidden();
        $this->getJson(operatorUrl($restaurant))->assertForbidden();
        $this->getJson(operatorUrl($restaurant, 'api-keys'))->assertForbidden();
    });

    test('using a key records when it was last used', function () {
        $restaurant = Restaurant::factory()->create();
        ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint('k', ApiKeyScope::restaurantScopes(), $restaurant);

        expect($key->last_used_at)->toBeNull();

        $this->withToken($plain)->getJson(API_BASE.'/operator/me')->assertOk();

        expect($key->fresh()->last_used_at)->not->toBeNull();
    });

    test('the key is stored hashed with a visible prefix', function () {
        ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint('k', [ApiKeyScope::All]);

        expect($plain)->toStartWith('pfk_test_')
            ->and(strlen($plain))->toBe(49)
            ->and($key->key_hash)->toBe(hash('sha256', $plain))
            ->and($key->key_prefix)->toBe(substr($plain, 0, 17))
            ->and($key->isPlatform())->toBeTrue();
    });
});

describe('operator tokens', function () {
    test('a customer-only token cannot enter the operator API', function () {
        $user = User::factory()->create();

        $this->withToken(apiTokenFor($user))->getJson(API_BASE.'/operator/me')
            ->assertForbidden()
            ->assertJsonPath('message', 'This token cannot operate restaurants.');
    });

    test('signing in as an admin issues the operator ability', function () {
        $restaurant = adminOrderRestaurant();
        $admin = adminForRestaurant($restaurant);
        $customer = User::factory()->create(['password' => 'password']);

        $this->postJson(API_BASE.'/auth/login', ['email' => $admin->email, 'password' => 'password', 'device_name' => 'iPad'])
            ->assertOk();
        expect($admin->tokens()->first()->abilities)->toBe(['customer', 'operator']);

        $this->postJson(API_BASE.'/auth/login', ['email' => $customer->email, 'password' => 'password', 'device_name' => 'iPhone'])
            ->assertOk();
        expect($customer->tokens()->first()->abilities)->toBe(['customer']);
    });

    test('a restaurant admin operates their restaurants with every restaurant scope', function () {
        $restaurant = adminOrderRestaurant();
        $other = Restaurant::factory()->create();
        $admin = adminForRestaurant($restaurant);

        $this->withToken(operatorTokenFor($admin))->getJson(API_BASE.'/operator/me')
            ->assertOk()
            ->assertJsonPath('data.type', 'user')
            ->assertJsonPath('data.isPlatform', false)
            ->assertJsonPath('data.scopes', [])
            ->assertJsonCount(1, 'data.restaurants')
            ->assertJsonPath('data.restaurants.0.role', 'admin')
            ->assertJsonPath('data.restaurants.0.scopes', array_map(fn (ApiKeyScope $s) => $s->value, ApiKeyScope::restaurantScopes()));

        $this->getJson(operatorUrl($restaurant, 'customers'))->assertOk();
        $this->getJson(operatorUrl($other))->assertNotFound();
    });

    test('staff run the kitchen but cannot read customers or manage keys', function () {
        $restaurant = adminOrderRestaurant();
        $staff = User::factory()->create();
        $staff->restaurants()->attach($restaurant->id, ['role' => 'staff']);
        $order = makeOrder($restaurant);

        $this->withToken(operatorTokenFor($staff));

        $this->getJson(operatorUrl($restaurant, 'orders'))->assertOk();
        $this->getJson(operatorUrl($restaurant, 'menu'))->assertOk();
        $this->postJson(operatorUrl($restaurant, "orders/{$order->number}/transition"), ['to_status' => 'confirmed'])->assertOk();
        $this->getJson(operatorUrl($restaurant, 'customers'))->assertForbidden();
        $this->getJson(operatorUrl($restaurant, 'api-keys'))->assertForbidden();
        $this->patchJson(operatorUrl($restaurant, 'menu-items/1/availability'), ['is_available' => false])->assertForbidden();
    });

    test('a super admin token reaches everything', function () {
        Restaurant::factory()->create();
        Restaurant::factory()->suspended()->create();
        $super = User::factory()->superAdmin()->create();

        $this->withToken(operatorTokenFor($super))->getJson(API_BASE.'/operator/me')
            ->assertOk()
            ->assertJsonPath('data.isPlatform', true)
            ->assertJsonPath('data.scopes', ['*'])
            ->assertJsonCount(2, 'data.restaurants');
    });
});

describe('api-key:create', function () {
    test('mints a platform key and prints it once', function () {
        $super = User::factory()->superAdmin()->create(['email' => 'taylor@example.test']);

        $this->artisan('api-key:create', ['name' => 'Claude', '--platform' => true, '--user' => 'taylor@example.test'])
            ->expectsOutputToContain('Platform key [Claude] created')
            ->expectsOutputToContain('pfk_test_')
            ->assertSuccessful();

        $key = ApiKey::query()->firstOrFail();

        expect($key->isPlatform())->toBeTrue()
            ->and($key->scopes)->toBe(['*'])
            ->and($key->created_by_user_id)->toBe($super->id);
    });

    test('mints a restaurant key with chosen scopes and an expiry', function () {
        $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);

        $this->artisan('api-key:create', [
            'name' => 'Kitchen tablet',
            '--restaurant' => 'marcos',
            '--scopes' => ['orders:read', 'orders:write'],
            '--expires' => '2030-01-01',
        ])->assertSuccessful();

        $key = ApiKey::query()->firstOrFail();

        expect($key->restaurant_id)->toBe($restaurant->id)
            ->and($key->scopes)->toBe(['orders:read', 'orders:write'])
            ->and($key->expires_at?->toDateString())->toBe('2030-01-01');
    });

    test('refuses ambiguous kinds, unknown restaurants and the wildcard on a restaurant key', function () {
        Restaurant::factory()->create(['subdomain' => 'marcos']);

        $this->artisan('api-key:create', ['name' => 'x'])->assertFailed();
        $this->artisan('api-key:create', ['name' => 'x', '--platform' => true, '--restaurant' => 'marcos'])->assertFailed();
        $this->artisan('api-key:create', ['name' => 'x', '--restaurant' => 'nowhere'])->assertFailed();
        $this->artisan('api-key:create', ['name' => 'x', '--restaurant' => 'marcos', '--scopes' => ['*']])->assertFailed();

        expect(ApiKey::query()->count())->toBe(0);
    });
});

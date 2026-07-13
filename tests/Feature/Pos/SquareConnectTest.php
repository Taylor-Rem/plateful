<?php

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Enums\RestaurantRole;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const SQUARE_ADMIN = 'http://admin.plateful.test';

beforeEach(function () {
    config()->set('platform.primary_domain', 'plateful.test');
    config()->set('services.square', [
        'application_id' => 'sandbox-app-id',
        'application_secret' => 'sandbox-secret',
        'environment' => 'sandbox',
        'redirect' => 'https://admin.plateful.test/pos/square/callback',
    ]);
});

/**
 * @return array{0: User, 1: Restaurant}
 */
function squareOwnerAndRestaurant(string $subdomain = 'pizzajoint'): array
{
    $owner = User::factory()->create();
    $restaurant = Restaurant::factory()->approved()->create([
        'subdomain' => $subdomain,
        'is_active' => true,
    ]);
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    return [$owner, $restaurant];
}

/**
 * @return array<string, mixed>
 */
function squareOauthSession(Restaurant $restaurant, string $state = 'valid-state', ?int $expiresAt = null): array
{
    return ['pos.square.oauth' => [
        'state' => $state,
        'restaurant_id' => $restaurant->id,
        'expires_at' => $expiresAt ?? now()->addMinutes(10)->timestamp,
    ]];
}

// --- connect ---------------------------------------------------------------

it('sends a restaurant admin to Square and stashes the oauth state', function () {
    [$owner, $restaurant] = squareOwnerAndRestaurant();

    $response = $this->actingAs($owner)
        ->post(SQUARE_ADMIN."/{$restaurant->subdomain}/settings/pos/square/connect");

    $response->assertStatus(302);
    expect($response->headers->get('Location'))
        ->toStartWith('https://connect.squareupsandbox.com/oauth2/authorize?');

    $stashed = session('pos.square.oauth');
    expect($stashed['restaurant_id'])->toBe($restaurant->id);
    expect($stashed['state'])->toBeString()->not->toBeEmpty();
});

it('forbids non-admin staff from connecting Square', function () {
    [, $restaurant] = squareOwnerAndRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->post(SQUARE_ADMIN."/{$restaurant->subdomain}/settings/pos/square/connect")
        ->assertForbidden();
});

// --- callback --------------------------------------------------------------

it('persists a connected integration on a successful callback', function () {
    [$owner, $restaurant] = squareOwnerAndRestaurant();

    Http::fake([
        'connect.squareupsandbox.com/oauth2/token' => Http::response([
            'access_token' => 'access-abc',
            'refresh_token' => 'refresh-xyz',
            'expires_at' => now()->addDays(30)->toIso8601String(),
            'merchant_id' => 'MERCHANT1',
        ]),
        'connect.squareupsandbox.com/v2/locations' => Http::response([
            'locations' => [['id' => 'L_ACTIVE', 'status' => 'ACTIVE']],
        ]),
    ]);

    $this->actingAs($owner)
        ->withSession(squareOauthSession($restaurant))
        ->get(SQUARE_ADMIN.'/pos/square/callback?code=auth-code&state=valid-state')
        ->assertRedirect(SQUARE_ADMIN."/{$restaurant->subdomain}/settings/pos")
        ->assertSessionHas('success');

    $integration = PosIntegration::withoutTenantScope()
        ->where('restaurant_id', $restaurant->id)
        ->where('provider', PosProviderName::Square->value)
        ->first();

    expect($integration)->not->toBeNull();
    expect($integration->status)->toBe(PosIntegrationStatus::Connected);
    expect($integration->access_token)->toBe('access-abc');
    expect($integration->refresh_token)->toBe('refresh-xyz');
    expect($integration->external_merchant_id)->toBe('MERCHANT1');
    expect($integration->location_id)->toBe('L_ACTIVE');
});

it('reconnects by updating the existing integration in place', function () {
    [$owner, $restaurant] = squareOwnerAndRestaurant();
    $existing = PosIntegration::factory()->tokenExpired()->create([
        'restaurant_id' => $restaurant->id,
        'access_token' => 'stale',
    ]);

    Http::fake([
        'connect.squareupsandbox.com/oauth2/token' => Http::response([
            'access_token' => 'access-fresh',
            'refresh_token' => 'refresh-fresh',
            'expires_at' => now()->addDays(30)->toIso8601String(),
            'merchant_id' => 'MERCHANT1',
        ]),
        'connect.squareupsandbox.com/v2/locations' => Http::response([
            'locations' => [['id' => 'L1', 'status' => 'ACTIVE']],
        ]),
    ]);

    $this->actingAs($owner)
        ->withSession(squareOauthSession($restaurant))
        ->get(SQUARE_ADMIN.'/pos/square/callback?code=auth-code&state=valid-state')
        ->assertRedirect(SQUARE_ADMIN."/{$restaurant->subdomain}/settings/pos");

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->count())->toBe(1);
    $existing->refresh();
    expect($existing->status)->toBe(PosIntegrationStatus::Connected);
    expect($existing->access_token)->toBe('access-fresh');
});

it('handles a cancelled authorization without touching credentials', function () {
    [$owner, $restaurant] = squareOwnerAndRestaurant();
    Http::fake();

    $this->actingAs($owner)
        ->withSession(squareOauthSession($restaurant))
        ->get(SQUARE_ADMIN.'/pos/square/callback?error=access_denied&state=valid-state')
        ->assertRedirect(SQUARE_ADMIN."/{$restaurant->subdomain}/settings/pos")
        ->assertSessionHas('error');

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('rejects a callback whose state does not match', function () {
    [$owner, $restaurant] = squareOwnerAndRestaurant();
    Http::fake();

    $this->actingAs($owner)
        ->withSession(squareOauthSession($restaurant, state: 'the-real-state'))
        ->get(SQUARE_ADMIN.'/pos/square/callback?code=auth-code&state=forged-state')
        ->assertRedirect(SQUARE_ADMIN);

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('rejects a callback whose state has expired', function () {
    [$owner, $restaurant] = squareOwnerAndRestaurant();
    Http::fake();

    $this->actingAs($owner)
        ->withSession(squareOauthSession($restaurant, expiresAt: now()->subMinute()->timestamp))
        ->get(SQUARE_ADMIN.'/pos/square/callback?code=auth-code&state=valid-state')
        ->assertRedirect(SQUARE_ADMIN);

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

// --- disconnect ------------------------------------------------------------

it('revokes and removes the integration on disconnect', function () {
    [$owner, $restaurant] = squareOwnerAndRestaurant();
    PosIntegration::factory()->create([
        'restaurant_id' => $restaurant->id,
        'access_token' => 'access-live',
    ]);

    Http::fake([
        'connect.squareupsandbox.com/oauth2/revoke' => Http::response(['success' => true]),
    ]);

    $this->actingAs($owner)
        ->post(SQUARE_ADMIN."/{$restaurant->subdomain}/settings/pos/square/disconnect")
        ->assertRedirect(SQUARE_ADMIN."/{$restaurant->subdomain}/settings/pos")
        ->assertSessionHas('success');

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->exists())->toBeFalse();
    Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth2/revoke'));
});

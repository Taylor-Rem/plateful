<?php

use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Enums\RestaurantRole;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

const CLOVER_ADMIN = 'http://admin.plateful.test';

beforeEach(function () {
    config()->set('platform.primary_domain', 'plateful.test');
    config()->set('services.clover', [
        'app_id' => 'sandbox-app-id',
        'app_secret' => 'sandbox-secret',
        'environment' => 'sandbox',
        'redirect' => 'https://admin.plateful.test/pos/clover/callback',
    ]);
});

/**
 * @return array{0: User, 1: Restaurant}
 */
function cloverOwnerAndRestaurant(string $subdomain = 'burgerbarn'): array
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
function cloverOauthSession(Restaurant $restaurant, string $state = 'valid-state', ?int $expiresAt = null): array
{
    return ['pos.clover.oauth' => [
        'state' => $state,
        'restaurant_id' => $restaurant->id,
        'expires_at' => $expiresAt ?? now()->addMinutes(10)->timestamp,
    ]];
}

function fakeCloverTokenExchange(): void
{
    Http::fake([
        'apisandbox.dev.clover.com/oauth/v2/token' => Http::response([
            'access_token' => 'access-abc',
            'access_token_expiration' => now()->addMinutes(30)->timestamp,
            'refresh_token' => 'refresh-xyz',
            'refresh_token_expiration' => now()->addDays(30)->timestamp,
        ]),
    ]);
}

// --- connect ---------------------------------------------------------------

it('sends a restaurant admin to Clover and stashes the oauth state', function () {
    [$owner, $restaurant] = cloverOwnerAndRestaurant();

    $response = $this->actingAs($owner)
        ->post(CLOVER_ADMIN."/{$restaurant->subdomain}/settings/pos/clover/connect");

    $response->assertStatus(302);
    expect($response->headers->get('Location'))
        ->toStartWith('https://sandbox.dev.clover.com/oauth/v2/authorize?');

    $stashed = session('pos.clover.oauth');
    expect($stashed['restaurant_id'])->toBe($restaurant->id);
    expect($stashed['state'])->toBeString()->not->toBeEmpty();
});

it('forbids non-admin staff from connecting Clover', function () {
    [, $restaurant] = cloverOwnerAndRestaurant();
    $staff = User::factory()->create();
    $restaurant->members()->attach($staff->id, ['role' => RestaurantRole::Staff->value]);

    $this->actingAs($staff)
        ->post(CLOVER_ADMIN."/{$restaurant->subdomain}/settings/pos/clover/connect")
        ->assertForbidden();
});

// --- callback --------------------------------------------------------------

it('persists a connected integration using the merchant id from the callback', function () {
    [$owner, $restaurant] = cloverOwnerAndRestaurant();

    fakeCloverTokenExchange();

    $this->actingAs($owner)
        ->withSession(cloverOauthSession($restaurant))
        ->get(CLOVER_ADMIN.'/pos/clover/callback?code=auth-code&state=valid-state&merchant_id=MID_9')
        ->assertRedirect(CLOVER_ADMIN."/{$restaurant->subdomain}/settings/pos")
        ->assertSessionHas('success');

    $integration = PosIntegration::withoutTenantScope()
        ->where('restaurant_id', $restaurant->id)
        ->where('provider', PosProviderName::Clover->value)
        ->first();

    expect($integration)->not->toBeNull();
    expect($integration->status)->toBe(PosIntegrationStatus::Connected);
    expect($integration->access_token)->toBe('access-abc');
    expect($integration->refresh_token)->toBe('refresh-xyz');
    expect($integration->external_merchant_id)->toBe('MID_9');
    expect($integration->location_id)->toBe('MID_9');
});

it('rejects a callback that is missing the merchant id', function () {
    [$owner, $restaurant] = cloverOwnerAndRestaurant();
    Http::fake();

    $this->actingAs($owner)
        ->withSession(cloverOauthSession($restaurant))
        ->get(CLOVER_ADMIN.'/pos/clover/callback?code=auth-code&state=valid-state')
        ->assertRedirect(CLOVER_ADMIN."/{$restaurant->subdomain}/settings/pos")
        ->assertSessionHas('error');

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('reconnects by updating the existing Clover integration in place', function () {
    [$owner, $restaurant] = cloverOwnerAndRestaurant();
    $existing = PosIntegration::factory()->tokenExpired()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => PosProviderName::Clover,
        'access_token' => 'stale',
    ]);

    fakeCloverTokenExchange();

    $this->actingAs($owner)
        ->withSession(cloverOauthSession($restaurant))
        ->get(CLOVER_ADMIN.'/pos/clover/callback?code=auth-code&state=valid-state&merchant_id=MID_9')
        ->assertRedirect(CLOVER_ADMIN."/{$restaurant->subdomain}/settings/pos");

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->count())->toBe(1);
    $existing->refresh();
    expect($existing->status)->toBe(PosIntegrationStatus::Connected);
    expect($existing->access_token)->toBe('access-abc');
});

it('handles a cancelled authorization without touching credentials', function () {
    [$owner, $restaurant] = cloverOwnerAndRestaurant();
    Http::fake();

    $this->actingAs($owner)
        ->withSession(cloverOauthSession($restaurant))
        ->get(CLOVER_ADMIN.'/pos/clover/callback?error=access_denied&state=valid-state')
        ->assertRedirect(CLOVER_ADMIN."/{$restaurant->subdomain}/settings/pos")
        ->assertSessionHas('error');

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('rejects a callback whose state does not match', function () {
    [$owner, $restaurant] = cloverOwnerAndRestaurant();
    Http::fake();

    $this->actingAs($owner)
        ->withSession(cloverOauthSession($restaurant, state: 'the-real-state'))
        ->get(CLOVER_ADMIN.'/pos/clover/callback?code=auth-code&state=forged-state&merchant_id=MID_9')
        ->assertRedirect(CLOVER_ADMIN);

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

it('rejects a callback whose state has expired', function () {
    [$owner, $restaurant] = cloverOwnerAndRestaurant();
    Http::fake();

    $this->actingAs($owner)
        ->withSession(cloverOauthSession($restaurant, expiresAt: now()->subMinute()->timestamp))
        ->get(CLOVER_ADMIN.'/pos/clover/callback?code=auth-code&state=valid-state&merchant_id=MID_9')
        ->assertRedirect(CLOVER_ADMIN);

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->exists())->toBeFalse();
    Http::assertNothingSent();
});

// --- disconnect ------------------------------------------------------------

it('removes the integration on disconnect', function () {
    [$owner, $restaurant] = cloverOwnerAndRestaurant();
    PosIntegration::factory()->create([
        'restaurant_id' => $restaurant->id,
        'provider' => PosProviderName::Clover,
        'access_token' => 'access-live',
    ]);

    $this->actingAs($owner)
        ->post(CLOVER_ADMIN."/{$restaurant->subdomain}/settings/pos/clover/disconnect")
        ->assertRedirect(CLOVER_ADMIN."/{$restaurant->subdomain}/settings/pos")
        ->assertSessionHas('success');

    expect(PosIntegration::withoutTenantScope()->where('restaurant_id', $restaurant->id)->exists())->toBeFalse();
});

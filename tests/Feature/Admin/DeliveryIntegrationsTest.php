<?php

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Models\DeliveryIntegration;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

function uberOkResponse(): array
{
    return [
        'access_token' => 'uber_tok_live',
        'token_type' => 'Bearer',
        'expires_in' => 2_592_000,
        'scope' => 'eats.deliveries',
    ];
}

/**
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function uberCredentials(array $overrides = []): array
{
    return array_merge([
        'client_id' => 'cid_paste',
        'client_secret' => 'csec_paste',
        'customer_id' => 'cust_paste',
    ], $overrides);
}

test('the delivery settings page lists Uber Direct as connectable', function () {
    $r = adminOrderRestaurant('ubershow');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/settings/delivery")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/TenantAdmin/DeliveryIntegrations')
            ->where('providers.0.provider', 'uber')
            ->where('providers.0.available', true)
            ->where('providers.0.status', 'disconnected'));
});

test('self-delivery is not listed as a credentialed integration', function () {
    $r = adminOrderRestaurant('uberself');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/settings/delivery")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where(
            'providers',
            fn ($providers) => collect($providers)->doesntContain(fn ($c) => $c['provider'] === 'self'),
        ));
});

test('pasting valid credentials verifies them with Uber and stores them encrypted', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberOkResponse())]);

    $r = adminOrderRestaurant('ubersave');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber", uberCredentials())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $integration = DeliveryIntegration::withoutTenantScope()
        ->where('restaurant_id', $r->id)
        ->firstOrFail();

    expect($integration->provider)->toBe(DeliveryProviderName::Uber);
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Connected);
    expect($integration->client_id)->toBe('cid_paste');
    expect($integration->customer_id)->toBe('cust_paste');

    // Verification already minted a token; reuse it rather than spend another
    // against the 100/hour grant limit.
    expect($integration->access_token)->toBe('uber_tok_live');
    Http::assertSentCount(1);

    // The cast must actually encrypt — the raw column may not hold plaintext.
    $raw = DB::table('delivery_integrations')->where('id', $integration->id)->value('client_secret');
    expect($raw)->not->toBe('csec_paste');
});

test('credentials Uber rejects are reported and never stored', function () {
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(['error' => 'invalid_client'], 401),
    ]);

    $r = adminOrderRestaurant('uberbad');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber", uberCredentials())
        ->assertRedirect()
        ->assertSessionHasErrors('client_id');

    // A typo must not land in the database looking connected.
    expect(DeliveryIntegration::withoutTenantScope()->count())->toBe(0);
});

test('re-pasting credentials updates the existing integration rather than duplicating it', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberOkResponse())]);

    $r = adminOrderRestaurant('uberagain');
    $u = adminForRestaurant($r);

    $this->actingAs($u)->post(
        "http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber",
        uberCredentials(),
    );
    $this->actingAs($u)->post(
        "http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber",
        uberCredentials(['customer_id' => 'cust_second']),
    );

    // The unique (restaurant_id, provider) index would throw rather than
    // duplicate — this proves we take the update path, not that path.
    expect(DeliveryIntegration::withoutTenantScope()->count())->toBe(1);
    expect(DeliveryIntegration::withoutTenantScope()->first()->customer_id)->toBe('cust_second');
});

test('pasted credentials are trimmed', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberOkResponse())]);

    $r = adminOrderRestaurant('ubertrim');
    $u = adminForRestaurant($r);

    $this->actingAs($u)->post(
        "http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber",
        uberCredentials(['client_id' => "  cid_paste\n"]),
    );

    expect(DeliveryIntegration::withoutTenantScope()->first()->client_id)->toBe('cid_paste');
});

test('every credential field is required', function () {
    Http::fake();

    $r = adminOrderRestaurant('uberreq');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber", [])
        ->assertSessionHasErrors(['client_id', 'client_secret', 'customer_id']);

    Http::assertNothingSent();
});

test('disconnecting clears the credentials', function () {
    $r = adminOrderRestaurant('uberdrop');
    $u = adminForRestaurant($r);
    DeliveryIntegration::factory()->create(['restaurant_id' => $r->id]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber/disconnect")
        ->assertRedirect();

    $integration = DeliveryIntegration::withoutTenantScope()->firstOrFail();
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Disconnected);
    expect($integration->client_secret)->toBeNull();
    expect($integration->access_token)->toBeNull();
});

test('an admin of another restaurant cannot read or write these credentials', function () {
    Http::fake([UberDirectTokenService::TOKEN_URL => Http::response(uberOkResponse())]);

    $mine = adminOrderRestaurant('ubermine');
    $theirs = adminOrderRestaurant('ubertheirs');
    $outsider = adminForRestaurant($theirs, 'outsider@m.test');

    $this->actingAs($outsider)
        ->get("http://admin.plateful.test/{$mine->subdomain}/settings/delivery")
        ->assertForbidden();

    $this->actingAs($outsider)
        ->post("http://admin.plateful.test/{$mine->subdomain}/settings/delivery/uber", uberCredentials())
        ->assertForbidden();

    expect(DeliveryIntegration::withoutTenantScope()->count())->toBe(0);
});

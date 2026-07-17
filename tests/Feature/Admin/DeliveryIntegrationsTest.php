<?php

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Models\DeliveryIntegration;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    // Platform-level DoorDash creds so the provisioning JWT can be minted.
    config(['services.doordash.developer_id' => 'dev_test']);
    config(['services.doordash.key_id' => 'key_test']);
    config(['services.doordash.signing_secret' => rtrim(strtr(base64_encode(str_repeat('k', 32)), '+/', '-_'), '=')]);
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

test('the delivery settings page leads with DoorDash and lists Uber as connectable', function () {
    $r = adminOrderRestaurant('ubershow');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/settings/delivery")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/TenantAdmin/DeliveryIntegrations')
            // DoorDash is the launch provider, so it leads and enables in one click.
            ->where('providers.0.provider', 'doordash')
            ->where('providers.0.available', true)
            ->where('providers.0.oneClick', true)
            ->where('providers.0.status', 'disconnected')
            // Uber stays connectable for restaurants bringing their own account.
            ->where('providers.1.provider', 'uber')
            ->where('providers.1.available', true)
            ->where('providers.1.oneClick', false));
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

// --- DoorDash Drive: one-click umbrella provisioning (Session 2) ------------

test('enabling DoorDash provisions a Business and Store and stores the ids', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(['result' => 'ok'], 200)]);

    $r = adminOrderRestaurant('ddsave');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/doordash")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $integration = DeliveryIntegration::withoutTenantScope()
        ->where('restaurant_id', $r->id)
        ->firstOrFail();

    expect($integration->provider)->toBe(DeliveryProviderName::DoorDash);
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Connected);
    expect($integration->external_business_id)->toBe('pf-biz-'.$r->id);
    expect($integration->external_store_id)->toBe('pf-store-'.$r->id);
    // No pasted secrets: these stay null for a platform-authenticated provider.
    expect($integration->client_id)->toBeNull();
    expect($integration->customer_id)->toBeNull();

    // One call to create the business, one to create the store under it.
    Http::assertSentCount(2);
});

test('enabling DoorDash sends the store address and a bearer token, never coordinates', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response([], 200)]);

    $r = adminOrderRestaurant('ddwire');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/doordash");

    Http::assertSent(function (Request $req) use ($r): bool {
        expect($req->hasHeader('Authorization'))->toBeTrue();
        expect($req->header('Authorization')[0])->toStartWith('Bearer ');

        if (str_ends_with($req->url(), '/stores')) {
            expect($req['external_store_id'])->toBe('pf-store-'.$r->id);
            expect($req['address'])->toBeString();
            expect($req)->not->toHaveKey('latitude');
        }

        return true;
    });
});

test('re-enabling DoorDash after a 409 is treated as success', function () {
    // DoorDash returns 409 for an id it already knows — a re-enable, not an error.
    Http::fake(['openapi.doordash.com/*' => Http::response(['error' => 'already exists'], 409)]);

    $r = adminOrderRestaurant('dd409');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/doordash")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $integration = DeliveryIntegration::withoutTenantScope()->firstOrFail();
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Connected);
    expect($integration->external_store_id)->toBe('pf-store-'.$r->id);
});

test('a provisioning failure is parked on the integration and no store id is stored', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(['error' => 'server error'], 500)]);

    $r = adminOrderRestaurant('ddfail');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/doordash")
        ->assertRedirect();

    $integration = DeliveryIntegration::withoutTenantScope()->firstOrFail();
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Error);
    expect($integration->last_error)->not->toBeNull();
    // A failed provision must not leave a store id that would make supports() true.
    expect($integration->external_store_id)->toBeNull();
});

test('disconnecting DoorDash clears the ids', function () {
    $r = adminOrderRestaurant('dddrop');
    $u = adminForRestaurant($r);
    DeliveryIntegration::factory()->doordash()->create(['restaurant_id' => $r->id]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/doordash/disconnect")
        ->assertRedirect();

    $integration = DeliveryIntegration::withoutTenantScope()->firstOrFail();
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Disconnected);
    expect($integration->external_store_id)->toBeNull();
    expect($integration->external_business_id)->toBeNull();
});

test('an outsider cannot enable DoorDash for another restaurant', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response([], 200)]);

    $mine = adminOrderRestaurant('ddmine');
    $theirs = adminOrderRestaurant('ddtheirs');
    $outsider = adminForRestaurant($theirs, 'ddoutsider@m.test');

    $this->actingAs($outsider)
        ->post("http://admin.plateful.test/{$mine->subdomain}/settings/delivery/doordash")
        ->assertForbidden();

    expect(DeliveryIntegration::withoutTenantScope()->count())->toBe(0);
    Http::assertNothingSent();
});

<?php

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Models\DeliveryIntegration;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/AdminOrderTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    // Platform-level DoorDash creds so the provisioning JWT can be minted.
    config(['services.doordash.developer_id' => 'dev_test']);
    config(['services.doordash.key_id' => 'key_test']);
    config(['services.doordash.signing_secret' => rtrim(strtr(base64_encode(str_repeat('k', 32)), '+/', '-_'), '=')]);
    // Platform-level Uber creds + the root org sub-orgs are provisioned under.
    config(['services.uber_direct.client_id' => 'cid_platform']);
    config(['services.uber_direct.client_secret' => 'csec_platform']);
    config(['services.uber_direct.customer_id' => 'root-org-uuid']);
});

function uberTokenOkResponse(): array
{
    return [
        'access_token' => 'uber_tok_live',
        'token_type' => 'Bearer',
        'expires_in' => 2_592_000,
        'scope' => 'direct.organizations',
    ];
}

function fakeUberProvisioning(string $organizationId = 'org-uuid-1'): void
{
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(uberTokenOkResponse()),
        'api.uber.com/v1/direct/organizations' => Http::response([
            'organization_id' => $organizationId,
            'info' => ['name' => 'Test', 'billing_type' => 'BILLING_TYPE_CENTRALIZED'],
        ]),
    ]);
}

test('the delivery settings page lists both couriers as one-click connectable', function () {
    $r = adminOrderRestaurant('ubershow');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->get("http://admin.plateful.test/{$r->subdomain}/settings/delivery")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/TenantAdmin/DeliveryIntegrations')
            ->where('providers.0.provider', 'doordash')
            ->where('providers.0.available', true)
            ->where('providers.0.status', 'disconnected')
            ->where('providers.1.provider', 'uber')
            ->where('providers.1.available', true)
            ->where('providers.1.status', 'disconnected'));
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

test('enabling Uber provisions a sub-organization and stores its id', function () {
    fakeUberProvisioning();

    $r = adminOrderRestaurant('ubersave');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $integration = DeliveryIntegration::withoutTenantScope()
        ->where('restaurant_id', $r->id)
        ->firstOrFail();

    expect($integration->provider)->toBe(DeliveryProviderName::Uber);
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Connected);
    // The server-generated org id IS the customer id every delivery call is
    // scoped under.
    expect($integration->customer_id)->toBe('org-uuid-1');
    // No pasted secrets: these stay null for a platform-authenticated provider.
    expect($integration->client_id)->toBeNull();

    // One grant (direct.organizations) + one org create.
    Http::assertSentCount(2);
});

test('enabling Uber creates a centralized, silently-invited org under the root account', function () {
    fakeUberProvisioning();

    $r = adminOrderRestaurant('uberwire');
    $r->forceFill(['name' => 'Rose / Thorn: 100% <Pizza>'])->save();
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber");

    Http::assertSent(function (Request $req): bool {
        if ($req->url() !== 'https://api.uber.com/v1/direct/organizations') {
            return false;
        }

        expect($req['info']['billing_type'])->toBe('BILLING_TYPE_CENTRALIZED');
        expect($req['info']['merchant_type'])->toBe('MERCHANT_TYPE_RESTAURANT');
        // Uber rejects contract_type under centralized billing (verified live);
        // it applies CONTRACT_TYPE_PARENT itself.
        expect($req['info'])->not->toHaveKey('contract_type');
        // Uber forbids URLs and / \ : % < > # = in org names.
        expect($req['info']['name'])->toBe('Rose Thorn 100 Pizza');
        expect($req['hierarchy_info']['parent_organization_id'])->toBe('root-org-uuid');
        // Silent provisioning: the restaurant never receives an Uber email.
        expect($req['options']['onboarding_invite_type'])->toBe('ONBOARDING_INVITE_TYPE_INVALID');

        return true;
    });
});

test('enabling Uber busts the cached platform tokens so the new org is reachable', function () {
    // A token only authorizes the orgs that existed when it was minted
    // (verified live: a pre-provisioning token gets 403 for the new org).
    fakeUberProvisioning();
    Cache::put('uber_direct.platform_token.eats.deliveries', 'stale_tok', now()->addDays(20));

    $r = adminOrderRestaurant('uberbust');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber");

    expect(Cache::get('uber_direct.platform_token.eats.deliveries'))->toBeNull();
});

test('re-enabling Uber reuses the existing org without calling Uber', function () {
    // Org ids are server-generated and orgs can only be deleted manually by
    // Uber — re-provisioning would mint a duplicate org forever.
    Http::fake();

    $r = adminOrderRestaurant('uberagain');
    $u = adminForRestaurant($r);
    DeliveryIntegration::factory()->disconnected()->create([
        'restaurant_id' => $r->id,
        'customer_id' => 'org-existing',
    ]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber")
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $integration = DeliveryIntegration::withoutTenantScope()->firstOrFail();
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Connected);
    expect($integration->customer_id)->toBe('org-existing');
    Http::assertNothingSent();
});

test('an Uber provisioning failure is parked on the integration and no org id is stored', function () {
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response(uberTokenOkResponse()),
        'api.uber.com/v1/direct/organizations' => Http::response(['message' => 'gateway error'], 500),
    ]);

    $r = adminOrderRestaurant('uberfail');
    $u = adminForRestaurant($r);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber")
        ->assertRedirect();

    $integration = DeliveryIntegration::withoutTenantScope()->firstOrFail();
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Error);
    expect($integration->last_error)->not->toBeNull();
    // A failed provision must not leave an org id that would make supports() true.
    expect($integration->customer_id)->toBeNull();
});

test('disconnecting Uber keeps the org id so re-enabling cannot duplicate the org', function () {
    $r = adminOrderRestaurant('uberdrop');
    $u = adminForRestaurant($r);
    DeliveryIntegration::factory()->create([
        'restaurant_id' => $r->id,
        'customer_id' => 'org-keep-me',
    ]);

    $this->actingAs($u)
        ->post("http://admin.plateful.test/{$r->subdomain}/settings/delivery/uber/disconnect")
        ->assertRedirect();

    $integration = DeliveryIntegration::withoutTenantScope()->firstOrFail();
    expect($integration->status)->toBe(DeliveryIntegrationStatus::Disconnected);
    expect($integration->customer_id)->toBe('org-keep-me');
});

test('an admin of another restaurant cannot read or enable this integration', function () {
    fakeUberProvisioning();

    $mine = adminOrderRestaurant('ubermine');
    $theirs = adminOrderRestaurant('ubertheirs');
    $outsider = adminForRestaurant($theirs, 'outsider@m.test');

    $this->actingAs($outsider)
        ->get("http://admin.plateful.test/{$mine->subdomain}/settings/delivery")
        ->assertForbidden();

    $this->actingAs($outsider)
        ->post("http://admin.plateful.test/{$mine->subdomain}/settings/delivery/uber")
        ->assertForbidden();

    expect(DeliveryIntegration::withoutTenantScope()->count())->toBe(0);
    Http::assertNothingSent();
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

        if (str_ends_with($req->url(), '/businesses')) {
            // DoorDash rejects a Business with no description, so we must always
            // send a non-empty one, even when the restaurant left it blank.
            expect($req['description'])->toBeString();
            expect(trim((string) $req['description']))->not->toBe('');
        }

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

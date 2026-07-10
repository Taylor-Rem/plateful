<?php

use App\Enums\PosProviderName;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use App\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('encrypts tokens at rest and decrypts them on access', function () {
    $integration = PosIntegration::factory()->create([
        'access_token' => 'tok_plaintext_secret',
        'refresh_token' => 'rtok_plaintext_secret',
    ]);

    $raw = DB::table('pos_integrations')->where('id', $integration->id)->first();

    expect($raw->access_token)->not->toBe('tok_plaintext_secret')
        ->and($raw->refresh_token)->not->toBe('rtok_plaintext_secret');

    $fresh = $integration->fresh();
    expect($fresh->access_token)->toBe('tok_plaintext_secret')
        ->and($fresh->refresh_token)->toBe('rtok_plaintext_secret');
});

it('scopes queries to the current tenant', function () {
    $a = Restaurant::factory()->create(['subdomain' => 'posa']);
    $b = Restaurant::factory()->create(['subdomain' => 'posb']);
    PosIntegration::factory()->create(['restaurant_id' => $a->id]);
    PosIntegration::factory()->create(['restaurant_id' => $b->id]);

    app(CurrentTenant::class)->set($a);

    expect(PosIntegration::query()->count())->toBe(1)
        ->and(PosIntegration::query()->first()->restaurant_id)->toBe($a->id);

    app(CurrentTenant::class)->clear();

    expect(PosIntegration::withoutTenantScope()->count())->toBe(2);
});

it('fills restaurant_id from the current tenant on create', function () {
    $r = Restaurant::factory()->create(['subdomain' => 'posfill']);
    app(CurrentTenant::class)->set($r);

    $integration = PosIntegration::create([
        'provider' => PosProviderName::Square,
    ]);

    expect($integration->restaurant_id)->toBe($r->id);

    app(CurrentTenant::class)->clear();
});

it('rejects a second integration for the same restaurant and provider', function () {
    $r = Restaurant::factory()->create(['subdomain' => 'posdupe']);
    PosIntegration::factory()->create(['restaurant_id' => $r->id]);

    expect(fn () => PosIntegration::factory()->create(['restaurant_id' => $r->id]))
        ->toThrow(QueryException::class);
});

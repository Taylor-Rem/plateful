<?php

use App\Models\Restaurant;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/ApiHelpers.php';

beforeEach(function () {
    // Phase 1 adds the real tenant-scoped routes; until then a probe route
    // exercises the middleware exactly as they will mount it.
    Route::domain(config('platform.primary_domain'))
        ->prefix('api/v1')
        ->middleware(['api', 'tenant.route'])
        ->get('restaurants/{restaurant}/tenant-probe', fn (CurrentTenant $tenant) => [
            'tenant_id' => $tenant->id(),
            'subdomain' => $tenant->get()?->subdomain,
        ]);
});

test('a live restaurant in the path becomes the current tenant', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);

    $this->getJson(API_BASE.'/restaurants/marcos/tenant-probe')
        ->assertOk()
        ->assertJsonPath('tenant_id', $restaurant->id)
        ->assertJsonPath('subdomain', 'marcos');
});

test('an unknown subdomain is a JSON 404', function () {
    $this->getJson(API_BASE.'/restaurants/nowhere/tenant-probe')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

test('a restaurant that is not live is a 404, never a preview', function (string $state) {
    Restaurant::factory()->{$state}()->create(['subdomain' => 'marcos']);

    $this->getJson(API_BASE.'/restaurants/marcos/tenant-probe')->assertNotFound();

    expect(app(CurrentTenant::class)->check())->toBeFalse();
})->with(['inactive', 'pendingReview', 'approved', 'suspended']);

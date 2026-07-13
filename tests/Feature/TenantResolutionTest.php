<?php

use App\Enums\RestaurantRole;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\StorefrontLoginHandoff;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['platform.admin_subdomain' => 'admin']);
});

function makeRestaurant(array $attrs = []): Restaurant
{
    return Restaurant::create(array_merge([
        'name' => 'Test Resto',
        'subdomain' => 'testresto',
        'email' => 'hello@testresto.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ], $attrs));
}

test('apex host does not bind tenant and serves home', function () {
    $response = $this->get('http://plateful.test/');

    $response->assertOk();
    expect(app(CurrentTenant::class)->check())->toBeFalse();
});

test('admin subdomain does not bind tenant', function () {
    makeRestaurant(['subdomain' => 'admin']);

    $this->get('http://admin.plateful.test/');

    expect(app(CurrentTenant::class)->check())->toBeFalse();
});

test('valid tenant subdomain resolves the restaurant', function () {
    $restaurant = makeRestaurant(['subdomain' => 'marcos']);

    $response = $this->get('http://marcos.plateful.test/');

    $response->assertOk();
    expect(app(CurrentTenant::class)->id())->toBe($restaurant->id);
});

test('custom domain resolves the restaurant', function () {
    $restaurant = makeRestaurant(['subdomain' => 'whatever', 'custom_domain' => 'pizza.example.com']);

    $response = $this->get('http://pizza.example.com/');

    $response->assertOk();
    expect(app(CurrentTenant::class)->id())->toBe($restaurant->id);
});

test('unknown host returns 404', function () {
    $response = $this->get('http://doesnotexist.plateful.test/');

    $response->assertNotFound();
});

test('deactivated tenant subdomain returns 503 with Unavailable component', function () {
    makeRestaurant(['subdomain' => 'sleeping', 'is_active' => false]);

    $response = $this->get('http://sleeping.plateful.test/');

    expect($response->status())->toBe(503);
    $response->assertInertia(fn ($page) => $page->component('Storefront/Unavailable'));
    expect(app(CurrentTenant::class)->check())->toBeFalse();
});

test('suspended restaurants render an Unavailable page on the storefront host', function () {
    Restaurant::factory()->suspended()->create(['subdomain' => 'gone', 'is_active' => true]);

    $this->get('http://gone.plateful.test/')->assertStatus(503);
});

test('approved-but-not-live restaurants are NOT served on the storefront host', function () {
    Restaurant::factory()->approved()->create(['subdomain' => 'soon', 'is_active' => true]);

    $this->get('http://soon.plateful.test/')->assertStatus(503);
});

test('a restaurant admin can preview their not-yet-live storefront', function () {
    $restaurant = Restaurant::factory()->approved()->create(['subdomain' => 'soon', 'is_active' => true]);
    $owner = User::factory()->create();
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    $this->actingAs($owner)
        ->get('http://soon.plateful.test/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('storefrontPreview', true));
});

test('authenticated users unrelated to the restaurant still get the pre-live wall', function () {
    Restaurant::factory()->approved()->create(['subdomain' => 'soon', 'is_active' => true]);
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->get('http://soon.plateful.test/')
        ->assertStatus(503);
});

test('live storefronts do not carry the preview flag', function () {
    $restaurant = Restaurant::factory()->create(['subdomain' => 'open', 'is_active' => true]);
    $owner = User::factory()->create();
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    $this->actingAs($owner)
        ->get('http://open.plateful.test/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('storefrontPreview'));
});

test('the preview handoff logs the owner in on the storefront host pre-live', function () {
    $restaurant = Restaurant::factory()->approved()->create(['subdomain' => 'soon', 'is_active' => true]);
    $owner = User::factory()->create();
    $restaurant->members()->attach($owner->id, ['role' => RestaurantRole::Admin->value]);

    $token = app(StorefrontLoginHandoff::class)->issue($owner, 'soon.plateful.test');

    $this->get('http://soon.plateful.test/preview/enter?token='.urlencode($token))
        ->assertRedirect('http://soon.plateful.test');

    expect(auth()->id())->toBe($owner->id);
});

test('an invalid preview token bounces back to the onboarding wizard', function () {
    Restaurant::factory()->approved()->create(['subdomain' => 'soon', 'is_active' => true]);

    $this->get('http://soon.plateful.test/preview/enter?token=garbage')
        ->assertRedirect('http://admin.plateful.test/soon/onboarding');

    expect(auth()->guest())->toBeTrue();
});

<?php

use App\Models\Restaurant;
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

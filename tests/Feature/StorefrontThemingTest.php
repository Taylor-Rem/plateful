<?php

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\BrandColors;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function themingTenant(?string $primary = '#b91c1c', ?string $secondary = null): Restaurant
{
    config(['platform.primary_domain' => 'plateful.test']);

    $r = Restaurant::create([
        'name' => "Marco's Pizza",
        'subdomain' => 'marcos',
        'email' => 'hello@marcos.test',
        'primary_color' => $primary,
        'secondary_color' => $secondary,
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ]);

    $cat = MenuCategory::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'name' => 'Pizzas',
        'slug' => 'pizzas',
    ]);

    MenuItem::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'menu_category_id' => $cat->id,
        'name' => 'Margherita',
        'slug' => 'margherita',
        'price_cents' => 1399,
    ]);

    return $r;
}

test('tenant storefront never renders the dark class even when appearance cookie is dark', function () {
    themingTenant();

    $response = $this->withUnencryptedCookie('appearance', 'dark')
        ->get('http://marcos.plateful.test/');

    $response->assertOk();
    expect($response->getContent())->not->toContain('class="dark"');
    expect($response->getContent())->toContain('data-appearance-context="tenant"');
});

test('admin host still applies the dark class when appearance cookie is dark', function () {
    config(['platform.primary_domain' => 'plateful.test']);

    $response = $this->withUnencryptedCookie('appearance', 'dark')
        ->get('http://admin.plateful.test/login');

    expect($response->getContent())->toContain('class="dark"');
    expect($response->getContent())->toContain('data-appearance-context="admin"');
});

test('storefront HTML includes brand primary as a CSS custom property', function () {
    themingTenant('#b91c1c');

    $response = $this->get('http://marcos.plateful.test/');

    $response->assertOk();
    expect($response->getContent())->toContain('--brand-primary: #b91c1c');
});

test('brand color fallback is used when the restaurant has no primary color', function () {
    themingTenant(null);

    $response = $this->get('http://marcos.plateful.test/');

    $response->assertOk();
    expect($response->getContent())->toContain('--brand-primary: '.BrandColors::FALLBACK_PRIMARY);
});

test('readable text color is white for dark backgrounds and dark for pale ones', function () {
    expect(BrandColors::readableTextColor('#171717'))->toBe('#ffffff');
    expect(BrandColors::readableTextColor('#ffeb3b'))->toBe('#0a0a0a');
    expect(BrandColors::readableTextColor('#ffffff'))->toBe('#0a0a0a');
});

test('settings update rejects invalid hex colors with a 422', function () {
    config(['platform.primary_domain' => 'plateful.test']);

    $r = themingTenant();
    $admin = User::factory()->admin()->create();
    $admin->restaurants()->attach($r->id);

    $response = $this->actingAs($admin)
        ->put("http://admin.plateful.test/{$r->subdomain}/settings", [
            'name' => $r->name,
            'description' => $r->description,
            'primary_color' => 'not-a-color',
            'secondary_color' => '#b91c1c',
            'email' => 'hello@marcos.test',
            'phone' => null,
        ]);

    $response->assertSessionHasErrors('primary_color');
});

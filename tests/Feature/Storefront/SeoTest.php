<?php

use App\Models\Restaurant;
use App\Models\RestaurantPhoto;
use App\Services\RestaurantImageService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake(RestaurantImageService::disk());
});

function seoRestaurant(array $overrides = []): Restaurant
{
    return Restaurant::create(array_merge([
        'name' => 'Marcos Pizza',
        'subdomain' => 'marcos',
        'email' => 'hello@marcos.test',
        'street' => '1 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ], $overrides));
}

function seoHtml(Restaurant $r): string
{
    return test()->get("http://{$r->subdomain}.plateful.test/")
        ->assertOk()
        ->getContent();
}

test('storefront renders restaurant name as title', function () {
    $r = seoRestaurant();

    $html = seoHtml($r);

    expect($html)->toContain('<title>Marcos Pizza</title>');
});

test('storefront renders og:title and og:site_name with restaurant name', function () {
    $r = seoRestaurant();

    $html = seoHtml($r);

    expect($html)->toContain('<meta property="og:title" content="Marcos Pizza">')
        ->and($html)->toContain('<meta property="og:site_name" content="Marcos Pizza">')
        ->and($html)->toContain('<meta property="og:type" content="restaurant">');
});

test('hero tagline is preferred for description', function () {
    $r = seoRestaurant([
        'description' => 'Generic description',
        'hero_tagline' => 'Wood-fired pizza since 1998.',
        'about_body' => 'Long about story...',
    ]);

    $html = seoHtml($r);

    expect($html)->toContain('<meta name="description" content="Wood-fired pizza since 1998.">')
        ->and($html)->toContain('<meta property="og:description" content="Wood-fired pizza since 1998.">');
});

test('about body is used when no hero tagline', function () {
    $r = seoRestaurant([
        'description' => 'Generic description',
        'about_body' => 'Brooklyn pizzeria with handmade dough.',
    ]);

    $html = seoHtml($r);

    expect($html)->toContain('Brooklyn pizzeria with handmade dough.');
});

test('description falls back when no hero or about', function () {
    $r = seoRestaurant(['description' => 'Just the description.']);

    $html = seoHtml($r);

    expect($html)->toContain('content="Just the description."');
});

test('description is capped at 160 chars and ellipsized', function () {
    $longTagline = str_repeat('a', 200);
    $r = seoRestaurant(['hero_tagline' => $longTagline]);

    // The capped description: 157 a's + ellipsis.
    expect($r->seoDescription())
        ->toBe(str_repeat('a', 157).'…');

    // And the meta tag in the HTML reflects the cap.
    $html = seoHtml($r);
    expect($html)->toContain('<meta name="description" content="'.str_repeat('a', 157).'…">');
});

test('og:image points at hero image when present', function () {
    $r = seoRestaurant();
    $r->hero_image_path = "restaurants/{$r->id}/hero/abc.webp";
    $r->save();

    $html = seoHtml($r);

    expect($html)->toContain('og:image')
        ->and($html)->toContain("restaurants/{$r->id}/hero/abc.webp");
});

test('og:image falls back to first gallery photo when no hero', function () {
    $r = seoRestaurant();

    $photo = RestaurantPhoto::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'position' => 0,
    ]);
    $photo->image_path = "restaurants/{$r->id}/gallery/1/zzz.webp";
    $photo->save();

    $html = seoHtml($r);

    expect($html)->toContain("restaurants/{$r->id}/gallery/1/zzz.webp");
});

test('twitter card is summary_large_image when og image exists', function () {
    $r = seoRestaurant();
    $r->hero_image_path = "restaurants/{$r->id}/hero/x.webp";
    $r->save();

    $html = seoHtml($r);

    expect($html)->toContain('<meta name="twitter:card" content="summary_large_image">');
});

test('twitter card falls back to summary without an image', function () {
    $r = seoRestaurant();

    $html = seoHtml($r);

    expect($html)->toContain('<meta name="twitter:card" content="summary">');
});

test('platform marketing page does not render tenantSeo block', function () {
    // Hitting the apex platform host should not produce restaurant-scoped OG.
    $r = seoRestaurant();

    $html = test()->get('http://plateful.test/for-restaurants')
        ->assertOk()
        ->getContent();

    expect($html)->not->toContain('og:type" content="restaurant');
});

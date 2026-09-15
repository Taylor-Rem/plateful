<?php

use App\Data\RestaurantData;
use App\Models\Restaurant;
use App\Models\RestaurantPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function presentationRestaurant(array $attributes = []): Restaurant
{
    config(['platform.primary_domain' => 'plateful.test']);

    return Restaurant::create(array_merge([
        'name' => 'Marcos Pizza',
        'subdomain' => 'marcos',
        'email' => 'hello@marcos.test',
        'street' => '123 Main',
        'city' => 'NYC',
        'state' => 'NY',
        'postal_code' => '10001',
    ], $attributes));
}

test('phone numbers are formatted for display with a tel href', function () {
    $r = presentationRestaurant(['phone' => '15555550123']);

    $data = RestaurantData::fromModel($r);

    expect($data->phoneDisplay)->toBe('(555) 555-0123')
        ->and($data->phoneHref)->toBe('tel:+15555550123');
});

test('ten digit phone numbers format the same way', function () {
    expect(RestaurantData::formatPhone('5555550123'))->toBe('(555) 555-0123')
        ->and(RestaurantData::phoneHref('5555550123'))->toBe('tel:+15555550123');
});

test('already formatted and international numbers pass through sensibly', function () {
    expect(RestaurantData::formatPhone('(555) 555-0123'))->toBe('(555) 555-0123')
        ->and(RestaurantData::formatPhone('+44 20 7946 0958'))->toBe('+44 20 7946 0958')
        ->and(RestaurantData::phoneHref('+44 20 7946 0958'))->toBe('tel:+442079460958');
});

test('missing phone yields null display and href', function () {
    expect(RestaurantData::formatPhone(null))->toBeNull()
        ->and(RestaurantData::formatPhone(' '))->toBeNull()
        ->and(RestaurantData::phoneHref(null))->toBeNull();
});

test('hasAboutSection reflects about content', function () {
    $r = presentationRestaurant();

    expect(RestaurantData::fromModel($r)->hasAboutSection)->toBeFalse();

    $r->update(['about_body' => 'Family-run since 1998.']);

    expect(RestaurantData::fromModel($r->fresh())->hasAboutSection)->toBeTrue();
});

test('hasGalleryPhotos reflects gallery photos', function () {
    $r = presentationRestaurant();

    expect(RestaurantData::fromModel($r)->hasGalleryPhotos)->toBeFalse();

    RestaurantPhoto::withoutTenantScope()->create([
        'restaurant_id' => $r->id,
        'caption' => 'Dining room',
        'position' => 0,
    ]);

    expect(RestaurantData::fromModel($r->fresh())->hasGalleryPhotos)->toBeTrue();
});

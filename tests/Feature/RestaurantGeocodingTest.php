<?php

use App\Jobs\GeocodeRestaurant;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Places\RestaurantGeocoder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function geocodeResponse(float $lat, float $lng): array
{
    return ['status' => 'OK', 'results' => [['geometry' => ['location' => ['lat' => $lat, 'lng' => $lng]]]]];
}

test('the geocoder stores coordinates from the Geocoding API', function () {
    config()->set('services.google.geocoding_api_key', 'geo-key');
    Http::fake([RestaurantGeocoder::ENDPOINT.'*' => Http::response(geocodeResponse(40.2338, -111.6585))]);

    $restaurant = Restaurant::factory()->create(['street' => '123 Center St', 'city' => 'Provo', 'state' => 'UT', 'postal_code' => '84601']);

    expect(app(RestaurantGeocoder::class)->geocode($restaurant))->toBeTrue();

    $restaurant->refresh();
    expect($restaurant->latitude)->toBe(40.2338)
        ->and($restaurant->longitude)->toBe(-111.6585)
        ->and($restaurant->geocoded_at)->not->toBeNull();

    Http::assertSent(fn ($request) => str_contains($request->url(), 'maps.googleapis.com/maps/api/geocode/json')
        && $request['address'] === '123 Center St, Provo, UT 84601'
        && $request['components'] === 'country:US'
        && $request['key'] === 'geo-key');
});

test('the geocoder is a no-op without a key or a street address', function () {
    Http::fake();

    $restaurant = Restaurant::factory()->create();
    expect(app(RestaurantGeocoder::class)->geocode($restaurant))->toBeFalse();

    config()->set('services.google.geocoding_api_key', 'geo-key');
    $blank = Restaurant::factory()->create(['street' => '', 'city' => '', 'state' => '']);
    expect(app(RestaurantGeocoder::class)->geocode($blank))->toBeFalse();

    Http::assertNothingSent();
});

test('a zero-results answer leaves the restaurant unplaced', function () {
    config()->set('services.google.geocoding_api_key', 'geo-key');
    Http::fake([RestaurantGeocoder::ENDPOINT.'*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []])]);

    $restaurant = Restaurant::factory()->create();

    expect(app(RestaurantGeocoder::class)->geocode($restaurant))->toBeFalse()
        ->and($restaurant->fresh()->latitude)->toBeNull();
});

test('saving a changed address from settings queues geocoding', function () {
    Queue::fake();
    $restaurant = Restaurant::factory()->create(['subdomain' => 'marcos']);
    $owner = User::factory()->create();
    $owner->restaurants()->attach($restaurant->id, ['role' => 'admin']);

    $this->actingAs($owner)->put('http://admin.plateful.test/marcos/settings', [
        'name' => $restaurant->name,
        'street' => '456 New Ave',
        'city' => $restaurant->city,
        'state' => $restaurant->state,
        'postal_code' => $restaurant->postal_code,
    ])->assertRedirect();

    Queue::assertPushed(GeocodeRestaurant::class, fn ($job) => $job->restaurantId === $restaurant->id);

    // Same address again: nothing to re-place.
    Queue::fake();
    $this->actingAs($owner)->put('http://admin.plateful.test/marcos/settings', [
        'name' => 'Renamed only',
        'street' => '456 New Ave',
        'city' => $restaurant->city,
        'state' => $restaurant->state,
        'postal_code' => $restaurant->postal_code,
    ])->assertRedirect();

    Queue::assertNotPushed(GeocodeRestaurant::class);
});

test('the backfill command geocodes unplaced restaurants and skips placed ones unless forced', function () {
    config()->set('services.google.geocoding_api_key', 'geo-key');
    Http::fake([RestaurantGeocoder::ENDPOINT.'*' => Http::response(geocodeResponse(41.0, -112.0))]);

    $unplaced = Restaurant::factory()->create();
    $placed = Restaurant::factory()->create(['latitude' => 1.0, 'longitude' => 2.0]);

    $this->artisan('restaurants:geocode')->assertSuccessful();

    expect($unplaced->fresh()->latitude)->toBe(41.0)
        ->and($placed->fresh()->latitude)->toBe(1.0);

    $this->artisan('restaurants:geocode', ['--force' => true])->assertSuccessful();

    expect($placed->fresh()->latitude)->toBe(41.0);
});

test('the backfill command fails clearly without a key', function () {
    $this->artisan('restaurants:geocode')->assertFailed();
});

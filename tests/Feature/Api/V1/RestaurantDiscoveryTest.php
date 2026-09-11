<?php

use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;

require_once __DIR__.'/ApiHelpers.php';

/**
 * @param  array<string, mixed>  $attributes
 */
function listedRestaurant(array $attributes = []): Restaurant
{
    return Restaurant::factory()->create([
        'marketplace_listed' => true,
        'latitude' => 40.2338,   // Provo, UT
        'longitude' => -111.6585,
        'timezone' => 'America/Denver',
        ...$attributes,
    ]);
}

test('the list returns live, listed restaurants as summary cards with pagination meta', function () {
    $r = listedRestaurant(['name' => 'Testaurant', 'subdomain' => 'testaurant', 'cuisine_tags' => ['italian', 'pizza'], 'city' => 'Provo', 'state' => 'UT']);

    $response = $this->getJson(API_BASE.'/restaurants');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $r->id)
        ->assertJsonPath('data.0.subdomain', 'testaurant')
        ->assertJsonPath('data.0.cuisineTags', ['italian', 'pizza'])
        ->assertJsonPath('data.0.distanceKm', null)
        ->assertJsonPath('data.0.isOpen', true)
        ->assertJsonPath('data.0.publicUrl', 'http://testaurant.plateful.test')
        ->assertJsonPath('meta', ['currentPage' => 1, 'lastPage' => 1, 'perPage' => 20, 'total' => 1])
        ->assertJsonStructure(['data' => [['id', 'name', 'subdomain', 'description', 'logoThumbUrl', 'logoMediumUrl', 'heroImageMediumUrl', 'city', 'state', 'latitude', 'longitude', 'distanceKm', 'cuisineTags', 'isOpen', 'openStatusLabel', 'deliveryEnabled', 'publicUrl']]]);
});

test('opted-out, inactive, and not-yet-live restaurants are not listed', function () {
    listedRestaurant(['name' => 'Listed']);
    listedRestaurant(['name' => 'Opted out', 'marketplace_listed' => false]);
    Restaurant::factory()->inactive()->create(['name' => 'Inactive', 'marketplace_listed' => true]);
    Restaurant::factory()->approved()->create(['name' => 'Onboarding', 'marketplace_listed' => true]);
    Restaurant::factory()->suspended()->create(['name' => 'Suspended', 'marketplace_listed' => true]);

    $this->getJson(API_BASE.'/restaurants')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Listed');
});

test('without a location the list is alphabetical', function () {
    listedRestaurant(['name' => 'Zeppole']);
    listedRestaurant(['name' => 'arepas']);
    listedRestaurant(['name' => 'Bao']);

    $names = $this->getJson(API_BASE.'/restaurants')->json('data.*.name');

    expect($names)->toBe(['arepas', 'Bao', 'Zeppole']);
});

test('with a location the list is distance-ordered within the radius and reports km', function () {
    $provo = listedRestaurant(['name' => 'Provo']);                                          // origin
    $orem = listedRestaurant(['name' => 'Orem', 'latitude' => 40.2969, 'longitude' => -111.6946]);   // ~7.6 km
    $slc = listedRestaurant(['name' => 'Salt Lake', 'latitude' => 40.7608, 'longitude' => -111.8910]); // ~61 km
    listedRestaurant(['name' => 'Unplaced', 'latitude' => null, 'longitude' => null]);

    $response = $this->getJson(API_BASE.'/restaurants?lat=40.2338&lng=-111.6585&radius_km=30');

    $response->assertOk()->assertJsonCount(2, 'data');
    expect($response->json('data.*.name'))->toBe(['Provo', 'Orem'])
        ->and($response->json('data.0.distanceKm'))->toEqual(0)
        ->and($response->json('data.1.distanceKm'))->toBeGreaterThan(6.0)->toBeLessThan(9.0);

    $wide = $this->getJson(API_BASE.'/restaurants?lat=40.2338&lng=-111.6585&radius_km=100');
    expect($wide->json('data.*.id'))->toBe([$provo->id, $orem->id, $slc->id]);
});

test('the radius is capped at the platform maximum and lat requires lng', function () {
    $this->getJson(API_BASE.'/restaurants?lat=40&lng=-111&radius_km=5000')
        ->assertUnprocessable()->assertJsonValidationErrors(['radius_km']);

    $this->getJson(API_BASE.'/restaurants?lat=40')
        ->assertUnprocessable()->assertJsonValidationErrors(['lng']);
});

test('cuisine, text, and delivery filters narrow the list', function () {
    listedRestaurant(['name' => 'Taqueria Sol', 'description' => 'Street tacos', 'cuisine_tags' => ['mexican'], 'delivery_enabled' => true]);
    listedRestaurant(['name' => 'Nonna', 'description' => 'Wood-fired pies', 'cuisine_tags' => ['italian', 'pizza'], 'delivery_enabled' => false]);
    listedRestaurant(['name' => 'Untagged', 'cuisine_tags' => null, 'delivery_enabled' => false]);

    expect($this->getJson(API_BASE.'/restaurants?cuisine=pizza')->json('data.*.name'))->toBe(['Nonna'])
        ->and($this->getJson(API_BASE.'/restaurants?q=TACO')->json('data.*.name'))->toBe(['Taqueria Sol'])
        ->and($this->getJson(API_BASE.'/restaurants?fulfilment=delivery')->json('data.*.name'))->toBe(['Taqueria Sol'])
        ->and($this->getJson(API_BASE.'/restaurants?fulfilment=pickup')->json('data'))->toHaveCount(3);

    $this->getJson(API_BASE.'/restaurants?cuisine=martian')
        ->assertUnprocessable()->assertJsonValidationErrors(['cuisine']);
});

test('open_now keeps only restaurants open at this moment in their own timezone', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-16 12:30:00', 'America/Denver')); // a Wednesday

    $open = listedRestaurant(['name' => 'Lunch spot']);
    RestaurantHour::create(['restaurant_id' => $open->id, 'day_of_week' => 3, 'opens_at' => '11:00:00', 'closes_at' => '14:00:00', 'position' => 0]);

    $closed = listedRestaurant(['name' => 'Dinner only']);
    RestaurantHour::create(['restaurant_id' => $closed->id, 'day_of_week' => 3, 'opens_at' => '17:00:00', 'closes_at' => '22:00:00', 'position' => 0]);

    listedRestaurant(['name' => 'No hours (always open)']);

    $names = $this->getJson(API_BASE.'/restaurants?open_now=1')->json('data.*.name');

    expect($names)->toBe(['Lunch spot', 'No hours (always open)']);

    $all = $this->getJson(API_BASE.'/restaurants')->json('data');
    expect(collect($all)->firstWhere('name', 'Dinner only')['isOpen'])->toBeFalse();

    CarbonImmutable::setTestNow();
});

test('the list paginates', function () {
    config()->set('platform.marketplace.per_page', 2);

    foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
        listedRestaurant(['name' => $name]);
    }

    $this->getJson(API_BASE.'/restaurants')
        ->assertJsonPath('data.*.name', ['A', 'B'])
        ->assertJsonPath('meta', ['currentPage' => 1, 'lastPage' => 3, 'perPage' => 2, 'total' => 5]);

    $this->getJson(API_BASE.'/restaurants?page=3')
        ->assertJsonPath('data.*.name', ['E'])
        ->assertJsonPath('meta.currentPage', 3);
});

test('the list needs no token and is not tenant-bound', function () {
    listedRestaurant();

    $this->getJson(API_BASE.'/restaurants')->assertOk();
    expect(app(CurrentTenant::class)->check())->toBeFalse();
});

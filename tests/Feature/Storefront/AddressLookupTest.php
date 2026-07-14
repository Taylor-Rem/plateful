<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/CartTestHelpers.php';

beforeEach(function () {
    config([
        'platform.primary_domain' => 'plateful.test',
        'services.google.maps_api_key' => 'test-maps-key',
    ]);

    // Per test, not cached in a static: RefreshDatabase would drop the
    // restaurant out from under a cached one and every host would 404.
    $this->restaurant = cartFixture()['restaurant'];
});

function lookupHost(): string
{
    return 'http://'.test()->restaurant->subdomain.'.plateful.test';
}

/**
 * Verbatim shape from the live Places (New) autocomplete response.
 */
function placesAutocompleteBody(): array
{
    return [
        'suggestions' => [
            [
                'placePrediction' => [
                    'place' => 'places/ChIJucecLhL1UocRCHY_F240b9s',
                    'placeId' => 'ChIJucecLhL1UocRCHY_F240b9s',
                    'text' => ['text' => '350 South 200 East, Salt Lake City, UT, USA'],
                    'structuredFormat' => [
                        'mainText' => ['text' => '350 South 200 East'],
                        'secondaryText' => ['text' => 'Salt Lake City, UT, USA'],
                    ],
                ],
            ],
        ],
    ];
}

/**
 * Verbatim component shape from the live Places (New) details response.
 */
function placesDetailsBody(array $extra = []): array
{
    return [
        'id' => 'ChIJucecLhL1UocRCHY_F240b9s',
        'formattedAddress' => '350 S 200 E, Salt Lake City, UT 84111, USA',
        'addressComponents' => array_merge([
            ['longText' => '350', 'shortText' => '350', 'types' => ['street_number']],
            ['longText' => 'South 200 East', 'shortText' => 'S 200 E', 'types' => ['route']],
            ['longText' => 'Salt Lake City', 'shortText' => 'Salt Lake City', 'types' => ['locality']],
            ['longText' => 'Utah', 'shortText' => 'UT', 'types' => ['administrative_area_level_1']],
            ['longText' => 'United States', 'shortText' => 'US', 'types' => ['country']],
            ['longText' => '84111', 'shortText' => '84111', 'types' => ['postal_code']],
        ], $extra),
    ];
}

it('returns suggestions without ever exposing the api key', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(placesAutocompleteBody())]);

    $response = $this->postJson(lookupHost().'/checkout/address/suggest', [
        'input' => '350 S 200',
        'session_token' => 'sess-1',
    ]);

    $response->assertOk();
    expect($response->json('suggestions.0.placeId'))->toBe('ChIJucecLhL1UocRCHY_F240b9s');
    expect($response->json('suggestions.0.mainText'))->toBe('350 South 200 East');

    // The whole point of proxying: the key is a server-side secret and must
    // never appear in anything the browser receives.
    expect($response->getContent())->not->toContain('test-maps-key');

    Http::assertSent(fn (Request $r): bool => $r->hasHeader('X-Goog-Api-Key', 'test-maps-key'));
});

it('passes the session token to both calls so Google bills one session', function () {
    Http::fake([
        'places.googleapis.com/v1/places:autocomplete' => Http::response(placesAutocompleteBody()),
        'places.googleapis.com/v1/places/*' => Http::response(placesDetailsBody()),
    ]);

    $this->postJson(lookupHost().'/checkout/address/suggest', [
        'input' => '350 S 200',
        'session_token' => 'sess-abc',
    ])->assertOk();

    $this->postJson(lookupHost().'/checkout/address/resolve', [
        'place_id' => 'ChIJucecLhL1UocRCHY_F240b9s',
        'session_token' => 'sess-abc',
    ])->assertOk();

    // Google bills autocomplete + details as one session only when the same
    // token rides on both. Miss it and every keystroke bills separately.
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'autocomplete')
        && $r['sessionToken'] === 'sess-abc');
    Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/v1/places/')
        && str_contains($r->url(), 'sessionToken=sess-abc'));
});

it('resolves a place into the exact snapshot shape the rest of the system speaks', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(placesDetailsBody())]);

    $response = $this->postJson(lookupHost().'/checkout/address/resolve', [
        'place_id' => 'ChIJucecLhL1UocRCHY_F240b9s',
        'session_token' => 'sess-1',
    ]);

    $response->assertOk();
    expect($response->json('address'))->toBe([
        'street' => '350 South 200 East',
        'street2' => '',
        'city' => 'Salt Lake City',
        'state' => 'UT',
        'postal_code' => '84111',
        'country' => 'US',
    ]);
});

it('uses the short state code, which is what Uber’s structured address wants', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(placesDetailsBody())]);

    $response = $this->postJson(lookupHost().'/checkout/address/resolve', [
        'place_id' => 'x',
        'session_token' => 'sess-1',
    ]);

    // "Utah" would be rejected or mis-geocoded; "UT" is the contract.
    expect($response->json('address.state'))->toBe('UT');
});

it('asks Google only for the fields it uses', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(placesDetailsBody())]);

    $this->postJson(lookupHost().'/checkout/address/resolve', [
        'place_id' => 'x',
        'session_token' => 'sess-1',
    ]);

    // Places (New) bills per requested field and rejects an unmasked request.
    Http::assertSent(fn (Request $r): bool => $r->hasHeader(
        'X-Goog-FieldMask',
        'id,formattedAddress,addressComponents',
    ));
});

it('never guesses the unit from the place result', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(placesDetailsBody([
        ['longText' => 'Apt 9', 'shortText' => 'Apt 9', 'types' => ['subpremise']],
    ]))]);

    $response = $this->postJson(lookupHost().'/checkout/address/resolve', [
        'place_id' => 'x',
        'session_token' => 'sess-1',
    ]);

    // Places is unreliable about units, which is why checkout asks for one in
    // its own field. Half-filling it from subpremise would be worse than blank.
    expect($response->json('address.street2'))->toBe('');
});

it('rejects a place with no street address rather than half-filling the snapshot', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([
        'id' => 'x',
        'addressComponents' => [
            ['longText' => 'Salt Lake City', 'shortText' => 'Salt Lake City', 'types' => ['locality']],
            ['longText' => 'Utah', 'shortText' => 'UT', 'types' => ['administrative_area_level_1']],
        ],
    ])]);

    // A partial address would only blow up later at the Uber quote, further
    // from the customer who could have fixed it.
    $this->postJson(lookupHost().'/checkout/address/resolve', [
        'place_id' => 'x',
        'session_token' => 'sess-1',
    ])->assertStatus(422);
});

it('degrades to no suggestions when Google errors rather than 500ing checkout', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(['error' => 'quota'], 429)]);

    $this->postJson(lookupHost().'/checkout/address/suggest', [
        'input' => '350 S 200',
        'session_token' => 'sess-1',
    ])->assertOk()->assertJson(['suggestions' => []]);
});

it('returns nothing and calls no one when no api key is configured', function () {
    config(['services.google.maps_api_key' => null]);
    Http::fake();

    $this->postJson(lookupHost().'/checkout/address/suggest', [
        'input' => '350 S 200',
        'session_token' => 'sess-1',
    ])->assertOk()->assertJson(['suggestions' => []]);

    Http::assertNothingSent();
});

it('requires an input and a session token', function () {
    Http::fake();

    $this->postJson(lookupHost().'/checkout/address/suggest', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['input', 'session_token']);

    Http::assertNothingSent();
});

it('is throttled, because every call costs money at Google', function () {
    Http::fake(['places.googleapis.com/*' => Http::response(placesAutocompleteBody())]);

    // 60/min. An unmetered public proxy is somebody else's free geocoding API.
    foreach (range(1, 60) as $i) {
        $this->postJson(lookupHost().'/checkout/address/suggest', [
            'input' => "350 S {$i}",
            'session_token' => 'sess-1',
        ])->assertOk();
    }

    $this->postJson(lookupHost().'/checkout/address/suggest', [
        'input' => '350 S 61',
        'session_token' => 'sess-1',
    ])->assertStatus(429);
});

<?php

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryIntegration;
use App\Models\Restaurant;
use App\Services\Delivery\DeliveryQuote;
use App\Services\Delivery\DeliveryQuoteRequest;
use App\Services\Delivery\DoorDash\DoorDashProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

const DOORDASH_STORE_ID = 'store_abc123';

beforeEach(function (): void {
    // Platform-level DoorDash credentials — one set for every restaurant. The
    // JWT they mint is never sent anywhere real; Http::fake intercepts.
    config()->set('services.doordash.developer_id', 'dev_test');
    config()->set('services.doordash.key_id', 'key_test');
    // 32 raw bytes, base64url-encoded, matching DoorDash's secret shape.
    config()->set('services.doordash.signing_secret', rtrim(strtr(base64_encode(str_repeat('k', 32)), '+/', '-_'), '='));
});

function doordashRestaurant(string $sub = 'ddco'): Restaurant
{
    $r = adminOrderRestaurant($sub);
    $r->forceFill([
        'phone' => '5551234567',
        'delivery_enabled' => true,
    ])->save();

    DeliveryIntegration::factory()->doordash()->create([
        'restaurant_id' => $r->id,
        'external_store_id' => DOORDASH_STORE_ID,
    ]);

    return $r->fresh();
}

/**
 * @return array<string, mixed>
 */
function doordashQuoteBody(array $overrides = []): array
{
    return array_merge([
        'external_delivery_id' => 'pf-quote-1',
        'currency' => 'USD',
        'fee' => 750,
        'pickup_time_estimated' => '2026-07-17T18:00:00Z',
        'dropoff_time_estimated' => '2026-07-17T18:30:00Z',
        'duration' => 30,
    ], $overrides);
}

/**
 * @return array<string, mixed>
 */
function doordashDeliveryBody(array $overrides = []): array
{
    return array_merge([
        'external_delivery_id' => 'pf-quote-1',
        'delivery_status' => 'created',
        'fee' => 750,
        'currency' => 'USD',
        'tracking_url' => 'https://doordash.com/tracking/abc',
        'support_reference' => 'DD-123',
        'pickup_time_estimated' => '2026-07-17T18:00:00Z',
        'dropoff_time_estimated' => '2026-07-17T18:30:00Z',
        'dasher_name' => null,
    ], $overrides);
}

function doordashQuoteRequestFor(Restaurant $r): DeliveryQuoteRequest
{
    return new DeliveryQuoteRequest(
        restaurant: $r,
        dropoffAddress: [
            'street' => '285 Fulton St',
            'street2' => 'Apt 4B',
            'city' => 'New York',
            'state' => 'NY',
            'postal_code' => '10006',
            'country' => 'US',
            'instructions' => 'Buzz twice',
        ],
        subtotalCents: 1400,
        tipCents: 200,
        customerName: 'Reese Ippient',
        customerPhone: '5555555555',
    );
}

it('quotes against the Drive quotes endpoint and maps the fee', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(doordashQuoteBody())]);
    $r = doordashRestaurant();

    $quote = app(DoorDashProvider::class)->quote(doordashQuoteRequestFor($r));

    expect($quote->provider)->toBe(DeliveryProviderName::DoorDash);
    expect($quote->feeCents)->toBe(750);
    expect($quote->etaMinutes)->toBe(30);
    expect($quote->externalQuoteId)->toBe('pf-quote-1');
    // DoorDash returns no expiry; we stamp a synthetic one so dispatch re-quotes
    // before the accept window lapses.
    expect($quote->expiresAt)->not->toBeNull();
    expect($quote->expiresAt->isFuture())->toBeTrue();

    Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
        && $req->url() === 'https://openapi.doordash.com/drive/v2/quotes');
});

it('sends the store identity and a bearer token, never coordinates', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(doordashQuoteBody())]);
    $r = doordashRestaurant();

    app(DoorDashProvider::class)->quote(doordashQuoteRequestFor($r));

    Http::assertSent(function (Request $req): bool {
        $body = $req->data();

        // Multi-location quotes MUST identify the store; this is what keys the
        // delivery to a restaurant under the umbrella account.
        expect($body['pickup_external_store_id'])->toBe(DOORDASH_STORE_ID);
        expect($body['pickup_external_business_id'])->not->toBeEmpty();
        expect($body['external_delivery_id'])->toStartWith('pf-');

        // Address is a geocodable string; no lat/lng is ever sent.
        expect($body['dropoff_address'])->toBeString();
        expect($body)->not->toHaveKey('dropoff_latitude');

        // The platform JWT authenticates every call.
        expect($req->hasHeader('Authorization'))->toBeTrue();
        expect($req->header('Authorization')[0])->toStartWith('Bearer ');

        return true;
    });
});

it('accepts the quote to create a delivery and records both fees', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(doordashDeliveryBody())]);

    $r = doordashRestaurant();
    $order = makeOrder($r);
    $order->forceFill(['delivery_address' => doordashQuoteRequestFor($r)->dropoffAddress])->save();

    $quote = new DeliveryQuote(
        provider: DeliveryProviderName::DoorDash,
        feeCents: 750,
        externalQuoteId: 'pf-quote-1',
    );

    $assignment = app(DoorDashProvider::class)->create($order->load('items'), $quote);

    expect($assignment->external_id)->toBe('pf-quote-1');
    expect($assignment->status)->toBe(DeliveryStatus::Pending);
    expect($assignment->tracking_url)->toBe('https://doordash.com/tracking/abc');
    expect($assignment->quote_fee_cents)->toBe(750);
    // DoorDash's fee excludes the tip, so it needs no stripping to stay
    // comparable with the quote.
    expect($assignment->actual_fee_cents)->toBe(750);

    Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
        && $req->url() === 'https://openapi.doordash.com/drive/v2/quotes/pf-quote-1/accept');
});

it('passes the customer tip through to the Dasher on accept', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(doordashDeliveryBody())]);

    $r = doordashRestaurant();
    $order = makeOrder($r);
    $order->forceFill([
        'delivery_address' => doordashQuoteRequestFor($r)->dropoffAddress,
        'tip_cents' => 500,
    ])->save();

    app(DoorDashProvider::class)->create($order->load('items'), new DeliveryQuote(
        provider: DeliveryProviderName::DoorDash,
        feeCents: 750,
        externalQuoteId: 'pf-quote-1',
    ));

    Http::assertSent(fn (Request $req): bool => str_contains($req->url(), '/accept')
        && $req['tip'] === 500);
});

it('re-quotes and accepts again when the first accept fails', function () {
    // First accept 400s (quote expired/consumed), then a fresh quote, then the
    // second accept succeeds — Risk R1.
    Http::fakeSequence('openapi.doordash.com/*')
        ->push(['message' => 'quote expired'], 400)
        ->push(doordashQuoteBody(['external_delivery_id' => 'pf-quote-2']))
        ->push(doordashDeliveryBody(['external_delivery_id' => 'pf-quote-2']));

    $r = doordashRestaurant();
    $order = makeOrder($r);
    $order->forceFill(['delivery_address' => doordashQuoteRequestFor($r)->dropoffAddress])->save();

    $assignment = app(DoorDashProvider::class)->create($order->load('items'), new DeliveryQuote(
        provider: DeliveryProviderName::DoorDash,
        feeCents: 750,
        externalQuoteId: 'pf-quote-1',
    ));

    // The assignment keys off the id we actually accepted, not the dead one.
    expect($assignment->external_id)->toBe('pf-quote-2');
    expect($assignment->status)->toBe(DeliveryStatus::Pending);

    $urls = [];
    Http::assertSent(function (Request $req) use (&$urls): bool {
        $urls[] = $req->method().' '.$req->url();

        return true;
    });

    expect($urls)->toBe([
        'POST https://openapi.doordash.com/drive/v2/quotes/pf-quote-1/accept',
        'POST https://openapi.doordash.com/drive/v2/quotes',
        'POST https://openapi.doordash.com/drive/v2/quotes/pf-quote-2/accept',
    ]);
});

it('maps DoorDash delivery statuses onto the Plateful vocabulary', function (string $ddStatus, DeliveryStatus $expected) {
    Http::fake(['openapi.doordash.com/*' => Http::response(doordashDeliveryBody(['delivery_status' => $ddStatus]))]);

    $r = doordashRestaurant();
    $order = makeOrder($r);
    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::DoorDash,
        'external_id' => 'pf-quote-1',
        'status' => DeliveryStatus::Pending,
    ]);

    expect(app(DoorDashProvider::class)->status($assignment)->status)->toBe($expected);
})->with([
    ['created', DeliveryStatus::Pending],
    ['confirmed', DeliveryStatus::DriverAssigned],
    ['enroute_to_pickup', DeliveryStatus::DriverAssigned],
    ['picked_up', DeliveryStatus::PickedUp],
    ['enroute_to_dropoff', DeliveryStatus::PickedUp],
    ['delivered', DeliveryStatus::Delivered],
    ['cancelled', DeliveryStatus::Cancelled],
    ['delivery_attempt_failed', DeliveryStatus::Failed],
]);

it('records Dasher details once DoorDash assigns one', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(doordashDeliveryBody([
        'delivery_status' => 'enroute_to_pickup',
        'dasher_name' => 'Alex',
        'dasher_dropoff_phone_number' => '+15550001111',
    ]))]);

    $r = doordashRestaurant();
    $order = makeOrder($r);
    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::DoorDash,
        'external_id' => 'pf-quote-1',
        'status' => DeliveryStatus::Pending,
    ]);

    $fresh = app(DoorDashProvider::class)->status($assignment);

    expect($fresh->driver_name)->toBe('Alex');
    expect($fresh->driver_phone)->toBe('+15550001111');
});

it('cancels a delivery via PUT and marks the assignment cancelled', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(['external_delivery_id' => 'pf-quote-1', 'delivery_status' => 'cancelled'])]);

    $r = doordashRestaurant();
    $order = makeOrder($r);
    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::DoorDash,
        'external_id' => 'pf-quote-1',
        'status' => DeliveryStatus::Pending,
    ]);

    app(DoorDashProvider::class)->cancel($assignment);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Cancelled);
    Http::assertSent(fn (Request $req): bool => $req->method() === 'PUT'
        && $req->url() === 'https://openapi.doordash.com/drive/v2/deliveries/pf-quote-1/cancel');
});

it('supports only restaurants with a provisioned store', function () {
    $connected = doordashRestaurant('ddyes');
    $bare = adminOrderRestaurant('ddno');

    $provider = app(DoorDashProvider::class);

    expect($provider->supports($connected))->toBeTrue();
    expect($provider->supports($bare))->toBeFalse();
});

it('does not support a restaurant whose integration lacks a store id', function () {
    $r = adminOrderRestaurant('ddnostore');
    DeliveryIntegration::factory()->doordash()->create([
        'restaurant_id' => $r->id,
        'external_store_id' => null,
    ]);

    expect(app(DoorDashProvider::class)->supports($r))->toBeFalse();
});

it('surfaces a DoorDash error rather than returning a broken quote', function () {
    Http::fake(['openapi.doordash.com/*' => Http::response(['message' => 'address undeliverable'], 400)]);
    $r = doordashRestaurant();

    expect(fn () => app(DoorDashProvider::class)->quote(doordashQuoteRequestFor($r)))
        ->toThrow(DeliveryProviderException::class, 'address undeliverable');
});

it('refuses to quote for a restaurant with no integration', function () {
    Http::fake();
    $r = adminOrderRestaurant('ddnone');

    expect(fn () => app(DoorDashProvider::class)->quote(doordashQuoteRequestFor($r)))
        ->toThrow(DeliveryProviderException::class, 'not configured');

    Http::assertNothingSent();
});

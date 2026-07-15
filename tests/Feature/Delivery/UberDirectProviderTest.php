<?php

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryIntegration;
use App\Models\Restaurant;
use App\Services\Delivery\DeliveryQuote;
use App\Services\Delivery\DeliveryQuoteRequest;
use App\Services\Delivery\UberDirect\UberDirectAddress;
use App\Services\Delivery\UberDirect\UberDirectProvider;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

const UBER_CUSTOMER_ID = 'cust_123';

function uberRestaurant(string $sub = 'uberco'): Restaurant
{
    $r = adminOrderRestaurant($sub);
    $r->forceFill([
        'phone' => '5551234567',
        'delivery_enabled' => true,
    ])->save();

    DeliveryIntegration::factory()->create([
        'restaurant_id' => $r->id,
        'customer_id' => UBER_CUSTOMER_ID,
        'access_token' => 'tok_live',
        'token_expires_at' => now()->addDays(20),
    ]);

    return $r->fresh();
}

/**
 * @return array<string, mixed>
 */
function uberQuoteBody(): array
{
    // Verbatim from Uber's own documented example response.
    return [
        'kind' => 'delivery_quote',
        'id' => 'dqt_AI6aDfhsSNqsVNTG03QKxg',
        'created' => '2023-07-07T02:36:57.776Z',
        'expires' => '2023-07-07T02:51:57.776Z',
        'fee' => 558,
        'currency' => 'usd',
        'dropoff_eta' => '2023-07-07T03:21:29Z',
        'duration' => 44,
        'pickup_duration' => 18,
        'dropoff_deadline' => '2023-07-07T03:57:42Z',
    ];
}

/**
 * @return array<string, mixed>
 */
function uberDeliveryBody(array $overrides = []): array
{
    return array_merge([
        'id' => 'del_Pw2e2GpnS0Gf0XUjb2xi3R',
        'quote_id' => 'dqt_AI6aDfhsSNqsVNTG03QKxg',
        'status' => 'pending',
        'fee' => 532,
        'tracking_url' => 'https://www.ubereats.com/orders/abc',
        'pickup_eta' => '2023-07-06T06:45:57Z',
        'dropoff_eta' => '2023-07-06T06:56:41Z',
        'courier' => null,
    ], $overrides);
}

function uberQuoteRequestFor(Restaurant $r): DeliveryQuoteRequest
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

it('quotes against the customer-scoped Direct endpoint', function () {
    Http::fake(['api.uber.com/*' => Http::response(uberQuoteBody())]);
    $r = uberRestaurant();

    $quote = app(UberDirectProvider::class)->quote(uberQuoteRequestFor($r));

    expect($quote->provider)->toBe(DeliveryProviderName::Uber);
    expect($quote->feeCents)->toBe(558);
    expect($quote->etaMinutes)->toBe(44);
    expect($quote->pickupDurationMinutes)->toBe(18);
    expect($quote->externalQuoteId)->toBe('dqt_AI6aDfhsSNqsVNTG03QKxg');
    expect($quote->expiresAt->toIso8601String())->toContain('2023-07-07');
    expect($quote->dropoffDeadlineAt)->not->toBeNull();

    Http::assertSent(fn (Request $req): bool => $req->method() === 'POST'
        && $req->url() === 'https://api.uber.com/v1/customers/'.UBER_CUSTOMER_ID.'/delivery_quotes');
});

it('sends addresses as JSON strings and never sends coordinates', function () {
    Http::fake(['api.uber.com/*' => Http::response(uberQuoteBody())]);
    $r = uberRestaurant();

    app(UberDirectProvider::class)->quote(uberQuoteRequestFor($r));

    Http::assertSent(function (Request $req): bool {
        $body = $req->data();

        // Uber expects a JSON-encoded *string*, not a nested object.
        expect($body['dropoff_address'])->toBeString();
        $decoded = json_decode($body['dropoff_address'], true);
        expect($decoded['street_address'])->toBe(['285 Fulton St', 'Apt 4B']);
        expect($decoded['zip_code'])->toBe('10006');

        // Coordinates more than 1km from the address make Uber silently
        // override them; we send address-only and let Uber geocode.
        expect($body)->not->toHaveKey('dropoff_latitude');
        expect($body)->not->toHaveKey('pickup_latitude');

        return true;
    });
});

it('replays the quote address byte-identically on create', function () {
    Http::fakeSequence()
        ->push(uberQuoteBody())
        ->push(uberDeliveryBody());

    $r = uberRestaurant();
    $order = makeOrder($r);
    $order->forceFill([
        'delivery_address' => uberQuoteRequestFor($r)->dropoffAddress,
        'customer_phone' => '5555555555',
    ])->save();

    $provider = app(UberDirectProvider::class);
    $quote = $provider->quote(uberQuoteRequestFor($r));
    $provider->create($order->load('items'), $quote);

    $sent = [];
    Http::assertSent(function (Request $req) use (&$sent): bool {
        $sent[] = $req->data();

        return true;
    });

    // Uber rejects a create whose address differs at all from its quote's.
    expect($sent[1]['dropoff_address'])->toBe($sent[0]['dropoff_address']);
    expect($sent[1]['pickup_address'])->toBe($sent[0]['pickup_address']);
});

it('creates a delivery and records both the quoted and actual fee', function () {
    Http::fake(['api.uber.com/*' => Http::response(uberDeliveryBody())]);

    $r = uberRestaurant();
    $order = makeOrder($r);
    $order->forceFill(['delivery_address' => uberQuoteRequestFor($r)->dropoffAddress])->save();

    $quote = new DeliveryQuote(
        provider: DeliveryProviderName::Uber,
        feeCents: 558,
        externalQuoteId: 'dqt_AI6aDfhsSNqsVNTG03QKxg',
        dropoffAddressPayload: UberDirectAddress::fromSnapshot(uberQuoteRequestFor($r)->dropoffAddress),
        pickupAddressPayload: UberDirectAddress::fromRestaurant($r),
    );

    $assignment = app(UberDirectProvider::class)->create($order->load('items'), $quote);

    expect($assignment->external_id)->toBe('del_Pw2e2GpnS0Gf0XUjb2xi3R');
    expect($assignment->status)->toBe(DeliveryStatus::Pending);
    expect($assignment->tracking_url)->toBe('https://www.ubereats.com/orders/abc');

    // Recording both is what makes the restaurant's drift exposure measurable
    // rather than a number someone invents later.
    expect($assignment->quote_fee_cents)->toBe(558);
    expect($assignment->actual_fee_cents)->toBe(532);

    Http::assertSent(fn (Request $req): bool => $req['quote_id'] === 'dqt_AI6aDfhsSNqsVNTG03QKxg'
        && $req['external_id'] === $order->number
        && $req['dropoff_notes'] === 'Buzz twice');
});

it('passes the customer tip through to the courier', function () {
    Http::fake(['api.uber.com/*' => Http::response(uberDeliveryBody())]);

    $r = uberRestaurant();
    $order = makeOrder($r);
    $order->forceFill([
        'delivery_address' => uberQuoteRequestFor($r)->dropoffAddress,
        'tip_cents' => 500,
    ])->save();

    app(UberDirectProvider::class)->create($order->load('items'), new DeliveryQuote(
        provider: DeliveryProviderName::Uber,
        feeCents: 558,
        externalQuoteId: 'dqt_x',
    ));

    // Uber's merchant terms require a courier tip reach the courier, and on a
    // third-party delivery the tip is unambiguously theirs. `tip` is the field
    // on DeliveryReq — `tip_by_customer` is update, `courier_tip` is the
    // store-scoped Eats API.
    Http::assertSent(fn (Request $req): bool => $req['tip'] === 500);
});

it('excludes the tip from actual_fee_cents so fee drift stays measurable', function () {
    // Uber's delivery `fee` INCLUDES the tip; a quote's fee cannot, since no
    // tip exists yet. Comparing them raw would read the tip as fee drift.
    Http::fake(['api.uber.com/*' => Http::response(uberDeliveryBody(['fee' => 1032]))]);

    $r = uberRestaurant();
    $order = makeOrder($r);
    $order->forceFill([
        'delivery_address' => uberQuoteRequestFor($r)->dropoffAddress,
        'tip_cents' => 500,
    ])->save();

    $assignment = app(UberDirectProvider::class)->create($order->load('items'), new DeliveryQuote(
        provider: DeliveryProviderName::Uber,
        feeCents: 558,
        externalQuoteId: 'dqt_x',
    ));

    // 1032 returned - 500 tip = 532 of actual delivery fee, against a 558
    // quote: the restaurant is 26c to the good, not 474c to the bad.
    expect($assignment->actual_fee_cents)->toBe(532);
    expect($assignment->quote_fee_cents)->toBe(558);
});

it('never lets a tip larger than the fee drive actual_fee_cents negative', function () {
    Http::fake(['api.uber.com/*' => Http::response(uberDeliveryBody(['fee' => 300]))]);

    $r = uberRestaurant();
    $order = makeOrder($r);
    $order->forceFill([
        'delivery_address' => uberQuoteRequestFor($r)->dropoffAddress,
        'tip_cents' => 900,
    ])->save();

    $assignment = app(UberDirectProvider::class)->create($order->load('items'), new DeliveryQuote(
        provider: DeliveryProviderName::Uber,
        feeCents: 558,
        externalQuoteId: 'dqt_x',
    ));

    expect($assignment->actual_fee_cents)->toBe(0);
});

it('derives the idempotency key from the order so a retry cannot dispatch two couriers', function () {
    Http::fake(['api.uber.com/*' => Http::response(uberDeliveryBody())]);

    $r = uberRestaurant();
    $order = makeOrder($r);
    $order->forceFill(['delivery_address' => uberQuoteRequestFor($r)->dropoffAddress])->save();

    app(UberDirectProvider::class)->create($order->load('items'), new DeliveryQuote(
        provider: DeliveryProviderName::Uber,
        feeCents: 558,
        externalQuoteId: 'dqt_x',
    ));

    // DispatchDeliveryForOrder retries 3 times, so a crash between Uber
    // creating the delivery and us saving the assignment would otherwise send a
    // second courier. The key must be a pure function of the order — a random
    // one would be worthless on the retry. (delivery_assignments.order_id is
    // unique, so our own table is already safe; this protects Uber's side.)
    Http::assertSent(fn (Request $req): bool => $req['idempotency_key'] === 'pf-delivery-'.$order->id);
});

it('sends a manifest describing what the courier carries', function () {
    Http::fake(['api.uber.com/*' => Http::response(uberDeliveryBody())]);

    $r = uberRestaurant();
    $order = makeOrder($r);
    $order->forceFill(['delivery_address' => uberQuoteRequestFor($r)->dropoffAddress])->save();

    $quote = new DeliveryQuote(
        provider: DeliveryProviderName::Uber,
        feeCents: 558,
        externalQuoteId: 'dqt_x',
    );

    app(UberDirectProvider::class)->create($order->load('items'), $quote);

    Http::assertSent(function (Request $req) use ($order): bool {
        expect($req['manifest_items'])->toHaveCount($order->items->count());
        expect($req['manifest_items'][0])->toHaveKeys(['name', 'quantity', 'size']);
        expect($req['manifest_reference'])->toBe($order->number);

        return true;
    });
});

it('maps Uber delivery statuses onto the Plateful vocabulary', function (string $uberStatus, DeliveryStatus $expected) {
    Http::fake(['api.uber.com/*' => Http::response(uberDeliveryBody(['status' => $uberStatus]))]);

    $r = uberRestaurant();
    $order = makeOrder($r);
    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::Uber,
        'external_id' => 'del_x',
        'status' => DeliveryStatus::Pending,
    ]);

    expect(app(UberDirectProvider::class)->status($assignment)->status)->toBe($expected);
})->with([
    ['pending', DeliveryStatus::Pending],
    ['pickup', DeliveryStatus::DriverAssigned],
    ['pickup_complete', DeliveryStatus::DriverAssigned],
    ['dropoff', DeliveryStatus::PickedUp],
    ['delivered', DeliveryStatus::Delivered],
    ['canceled', DeliveryStatus::Cancelled],
    ['returned', DeliveryStatus::Failed],
]);

it('records courier details once Uber assigns one', function () {
    Http::fake(['api.uber.com/*' => Http::response(uberDeliveryBody([
        'status' => 'pickup',
        'courier' => ['name' => 'Alex', 'phone_number' => '+15550001111'],
    ]))]);

    $r = uberRestaurant();
    $order = makeOrder($r);
    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::Uber,
        'external_id' => 'del_x',
        'status' => DeliveryStatus::Pending,
    ]);

    $fresh = app(UberDirectProvider::class)->status($assignment);

    expect($fresh->driver_name)->toBe('Alex');
    expect($fresh->driver_phone)->toBe('+15550001111');
});

it('cancels a delivery and marks the assignment cancelled', function () {
    Http::fake(['api.uber.com/*' => Http::response(['id' => 'del_x', 'status' => 'canceled'])]);

    $r = uberRestaurant();
    $order = makeOrder($r);
    $assignment = DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::Uber,
        'external_id' => 'del_x',
        'status' => DeliveryStatus::Pending,
    ]);

    app(UberDirectProvider::class)->cancel($assignment);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Cancelled);
    Http::assertSent(fn (Request $req): bool => $req->url()
        === 'https://api.uber.com/v1/customers/'.UBER_CUSTOMER_ID.'/deliveries/del_x/cancel');
});

it('supports only restaurants with a connected integration', function () {
    $connected = uberRestaurant('uberyes');
    $bare = adminOrderRestaurant('uberno');

    $provider = app(UberDirectProvider::class);

    expect($provider->supports($connected))->toBeTrue();
    expect($provider->supports($bare))->toBeFalse();
});

it('does not support a restaurant whose integration is disconnected', function () {
    $r = adminOrderRestaurant('uberoff');
    DeliveryIntegration::factory()->disconnected()->create(['restaurant_id' => $r->id]);

    expect(app(UberDirectProvider::class)->supports($r))->toBeFalse();
});

it('surfaces an Uber error rather than returning a broken quote', function () {
    Http::fake(['api.uber.com/*' => Http::response(['message' => 'address undeliverable'], 400)]);
    $r = uberRestaurant();

    expect(fn () => app(UberDirectProvider::class)->quote(uberQuoteRequestFor($r)))
        ->toThrow(DeliveryProviderException::class, 'address undeliverable');
});

it('refuses to quote for a restaurant with no integration', function () {
    Http::fake();
    $r = adminOrderRestaurant('ubernone');

    expect(fn () => app(UberDirectProvider::class)->quote(uberQuoteRequestFor($r)))
        ->toThrow(DeliveryProviderException::class, 'not configured');

    Http::assertNothingSent();
});

it('mints a token before calling the API when none is stored', function () {
    Http::fake([
        UberDirectTokenService::TOKEN_URL => Http::response([
            'access_token' => 'fresh_tok',
            'expires_in' => 2_592_000,
        ]),
        'api.uber.com/v1/customers/*' => Http::response(uberQuoteBody()),
    ]);

    $r = adminOrderRestaurant('ubermint');
    DeliveryIntegration::factory()->withoutToken()->create([
        'restaurant_id' => $r->id,
        'customer_id' => UBER_CUSTOMER_ID,
    ]);

    app(UberDirectProvider::class)->quote(uberQuoteRequestFor($r->fresh()));

    Http::assertSent(fn (Request $req): bool => str_contains($req->url(), 'delivery_quotes')
        && $req->hasHeader('Authorization', 'Bearer fresh_tok'));
});

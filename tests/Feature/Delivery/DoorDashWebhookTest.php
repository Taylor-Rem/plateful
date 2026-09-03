<?php

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Models\DeliveryAssignment;
use App\Models\OrderEvent;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

// What the operator typed into the portal's "Basic Auth" box — the exact
// Authorization header DoorDash sends on every event.
const DOORDASH_WEBHOOK_SECRET = 'Basic cGxhdGVmdWw6d2hzZWNfZG9vcmRhc2hfdGVzdA==';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['services.doordash.webhook_secret' => DOORDASH_WEBHOOK_SECRET]);
    Mail::fake();
});

function doordashWebhookUrl(): string
{
    return 'http://admin.plateful.test/webhooks/doordash';
}

/**
 * @param  array<string, mixed>  $payload
 */
function postDoorDashWebhook(array $payload, ?string $authorization = DOORDASH_WEBHOOK_SECRET)
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];
    if ($authorization !== null) {
        $server['HTTP_AUTHORIZATION'] = $authorization;
    }

    return test()->call('POST', doordashWebhookUrl(), [], [], [], $server, $body);
}

/**
 * @return array<string, mixed>
 */
function doordashWebhookPayload(array $overrides = []): array
{
    // Shaped like DoorDash's webhook reference: the trigger is `event_name`;
    // there is no `delivery_status` field on a webhook.
    return array_merge([
        'external_delivery_id' => 'pf-del-1',
        // DASHER_CONFIRMED → 'confirmed' → DriverAssigned (a Dasher is committed).
        'event_name' => 'DASHER_CONFIRMED',
        'created_at' => '2026-07-17T12:00:00.000Z',
        'tracking_url' => 'https://doordash.com/track/abc',
        'fee' => 900,
        'dasher_name' => 'Dana',
        'dasher_dropoff_phone_number' => '+15551230000',
    ], $overrides);
}

function seedDoorDashWebhookFixture(string $status = 'pending'): DeliveryAssignment
{
    $restaurant = adminOrderRestaurant('ddhookco');
    $order = makeOrder($restaurant);

    return DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::DoorDash,
        'external_id' => 'pf-del-1',
        'status' => DeliveryStatus::from($status),
    ]);
}

it('applies an authorized delivery status event', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload())->assertOk();

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe(DeliveryStatus::DriverAssigned);
    expect($fresh->provider_status)->toBe('confirmed');
    expect($fresh->driver_name)->toBe('Dana');
    expect($fresh->driver_phone)->toBe('+15551230000');
    expect($fresh->tracking_url)->toBe('https://doordash.com/track/abc');
    // DoorDash's fee excludes the tip, so it is stored as-is.
    expect($fresh->actual_fee_cents)->toBe(900);
});

it('captures the support reference when an event carries one', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(['support_reference' => 'DD-987']))->assertOk();

    expect($assignment->fresh()->support_reference)->toBe('DD-987');
});

it('accepts a bare user:password secret against the standard Basic encoding', function () {
    config(['services.doordash.webhook_secret' => 'plateful:whsec_doordash_test']);
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(), authorization: 'Basic '.base64_encode('plateful:whsec_doordash_test'))->assertOk();

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::DriverAssigned);
});

it('rejects an event whose Authorization header does not match', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(), authorization: 'Basic bm90LXRoZS1yZWFsLXNlY3JldA==')->assertStatus(400);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('rejects an event with no Authorization header', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(), authorization: null)->assertStatus(400);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('maps every lifecycle event name onto the shared status', function (string $eventName, string $providerStatus, DeliveryStatus $expected) {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(['event_name' => $eventName]))->assertOk();

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe($expected);
    expect($fresh->provider_status)->toBe($providerStatus);
})->with([
    ['DASHER_CONFIRMED', 'confirmed', DeliveryStatus::DriverAssigned],
    ['dasher_enroute_to_pickup', 'enroute_to_pickup', DeliveryStatus::DriverAssigned],
    ['DASHER_CONFIRMED_PICKUP_ARRIVAL', 'arrived_at_store', DeliveryStatus::DriverAssigned],
    ['DASHER_PICKED_UP', 'picked_up', DeliveryStatus::PickedUp],
    ['dasher_enroute_to_dropoff', 'enroute_to_dropoff', DeliveryStatus::PickedUp],
    ['DASHER_CONFIRMED_DROPOFF_ARRIVAL', 'arrived_at_consumer', DeliveryStatus::PickedUp],
    ['DASHER_DROPPED_OFF', 'delivered', DeliveryStatus::Delivered],
    ['DELIVERY_CANCELLED', 'cancelled', DeliveryStatus::Cancelled],
    ['DELIVERY_RETURN_INITIALIZED', 'delivery_attempt_failed', DeliveryStatus::Failed],
    ['DELIVERY_RETURNED', 'returned', DeliveryStatus::Failed],
]);

it('keeps the current status on an event with no lifecycle meaning', function () {
    $assignment = seedDoorDashWebhookFixture('driver_assigned');

    postDoorDashWebhook(doordashWebhookPayload([
        'event_name' => 'DELIVERY_BATCHED',
        'force_batch_id' => 'batch-1',
        'dasher_name' => 'Dana',
    ]))->assertOk();

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe(DeliveryStatus::DriverAssigned);
    expect($fresh->driver_name)->toBe('Dana');
});

it('prefers delivery_status when a payload carries one', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(['event_name' => 'DASHER_CONFIRMED', 'delivery_status' => 'delivered']))->assertOk();

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('rejects every event when no webhook secret is configured', function () {
    config(['services.doordash.webhook_secret' => '']);
    $assignment = seedDoorDashWebhookFixture();

    // Fail closed: with no secret we can vouch for nothing.
    postDoorDashWebhook(doordashWebhookPayload())->assertStatus(400);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('acknowledges a delivery it has no record of so DoorDash stops retrying', function () {
    seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(['external_delivery_id' => 'pf-unknown']))->assertOk();
});

it('drops a retried event older than one already applied', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload([
        'event_name' => 'DASHER_DROPPED_OFF',
        'created_at' => '2026-07-17T12:30:00.000Z',
    ]))->assertOk();
    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Delivered);

    postDoorDashWebhook(doordashWebhookPayload([
        'event_name' => 'DASHER_CONFIRMED',
        'created_at' => '2026-07-17T12:00:00.000Z',
    ]))->assertOk();

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('applies a newer event', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(['event_name' => 'DASHER_CONFIRMED', 'created_at' => '2026-07-17T12:00:00.000Z']));
    postDoorDashWebhook(doordashWebhookPayload(['event_name' => 'DASHER_PICKED_UP', 'created_at' => '2026-07-17T12:10:00.000Z']));

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::PickedUp);
});

it('notes each status change on the order timeline, but not a repeat', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(['event_name' => 'DASHER_CONFIRMED', 'created_at' => '2026-07-17T12:00:00.000Z']));
    postDoorDashWebhook(doordashWebhookPayload(['event_name' => 'DASHER_CONFIRMED', 'created_at' => '2026-07-17T12:05:00.000Z']));

    $notes = OrderEvent::query()->where('order_id', $assignment->order_id)->get();
    expect($notes)->toHaveCount(1);
    expect($notes->first()->note)->toContain('driver_assigned');
    expect($notes->first()->note)->toContain('DoorDash');
});

it('captures an authorized order when a Dasher is confirmed', function () {
    $assignment = seedDoorDashWebhookFixture();
    $assignment->order->forceFill([
        'payment_state' => PaymentState::Authorized,
        'authorized_at' => now(),
        'stripe_payment_intent_id' => 'pi_dd_1',
    ])->save();

    $stripe = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $stripe);
    $stripe->shouldReceive('capturePayment')->once();

    postDoorDashWebhook(doordashWebhookPayload(['event_name' => 'DASHER_CONFIRMED']))->assertOk();

    expect($assignment->order->fresh()->payment_state)->toBe(PaymentState::Captured);
});

it('voids an authorized order when the delivery is cancelled', function () {
    $assignment = seedDoorDashWebhookFixture();
    $assignment->order->forceFill([
        'payment_state' => PaymentState::Authorized,
        'authorized_at' => now(),
        'stripe_payment_intent_id' => 'pi_dd_2',
    ])->save();

    $stripe = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $stripe);
    $stripe->shouldReceive('voidPayment')->once();

    postDoorDashWebhook(doordashWebhookPayload(['event_name' => 'DELIVERY_CANCELLED', 'cancellation_reason' => 'cancel_by_dispatch']))->assertOk();

    $order = $assignment->order->fresh();
    expect($order->payment_state)->toBe(PaymentState::Voided);
    expect($order->status)->toBe(OrderStatus::Cancelled);
});

it('does not touch the money on a captured order', function () {
    $assignment = seedDoorDashWebhookFixture();

    $stripe = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $stripe);
    $stripe->shouldNotReceive('capturePayment');
    $stripe->shouldNotReceive('voidPayment');

    postDoorDashWebhook(doordashWebhookPayload(['event_name' => 'DASHER_CONFIRMED']))->assertOk();
});

it('is exempt from CSRF so DoorDash can actually reach it', function () {
    seedDoorDashWebhookFixture();

    // A 419 here would mean every real webhook silently fails in production.
    postDoorDashWebhook(doordashWebhookPayload())->assertOk();
});

it('exposes hasCourier on the shared status enum for both providers', function (DeliveryStatus $status, bool $expected) {
    expect($status->hasCourier())->toBe($expected);
})->with([
    [DeliveryStatus::Pending, false],
    [DeliveryStatus::DriverAssigned, true],
    [DeliveryStatus::PickedUp, true],
    [DeliveryStatus::Delivered, true],
    [DeliveryStatus::Cancelled, false],
    [DeliveryStatus::Failed, false],
]);

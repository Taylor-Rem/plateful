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

const DOORDASH_WEBHOOK_SECRET = 'whsec_doordash_test';

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
function postDoorDashWebhook(array $payload, ?string $signature = null, ?string $secret = DOORDASH_WEBHOOK_SECRET)
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    // Default: sign the body exactly as the controller expects (base64 HMAC).
    if ($signature === null && $secret !== null) {
        $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));
    }

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ];
    if ($signature !== null) {
        $server['HTTP_X_DOORDASH_SIGNATURE'] = $signature;
    }

    return test()->call('POST', doordashWebhookUrl(), [], [], [], $server, $body);
}

/**
 * @return array<string, mixed>
 */
function doordashWebhookPayload(array $overrides = []): array
{
    return array_merge([
        'external_delivery_id' => 'pf-del-1',
        // 'confirmed' → DriverAssigned (a Dasher is committed).
        'delivery_status' => 'confirmed',
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

it('applies a correctly signed delivery status event', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload())->assertOk();

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe(DeliveryStatus::DriverAssigned);
    expect($fresh->driver_name)->toBe('Dana');
    expect($fresh->driver_phone)->toBe('+15551230000');
    expect($fresh->tracking_url)->toBe('https://doordash.com/track/abc');
    // DoorDash's fee excludes the tip, so it is stored as-is.
    expect($fresh->actual_fee_cents)->toBe(900);
});

it('accepts a hex-encoded signature too', function () {
    $assignment = seedDoorDashWebhookFixture();

    $body = json_encode(doordashWebhookPayload(), JSON_THROW_ON_ERROR);
    $hex = hash_hmac('sha256', $body, DOORDASH_WEBHOOK_SECRET);

    postDoorDashWebhook(doordashWebhookPayload(), signature: $hex)->assertOk();

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::DriverAssigned);
});

it('rejects an event signed with the wrong secret', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(), secret: 'not-the-real-secret')->assertStatus(400);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('rejects an unsigned event', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(), secret: null)->assertStatus(400);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('rejects a payload whose body was tampered with after signing', function () {
    $assignment = seedDoorDashWebhookFixture();

    $signedBody = json_encode(doordashWebhookPayload(), JSON_THROW_ON_ERROR);
    $signature = base64_encode(hash_hmac('sha256', $signedBody, DOORDASH_WEBHOOK_SECRET, true));
    $tampered = json_encode(doordashWebhookPayload(['delivery_status' => 'delivered']), JSON_THROW_ON_ERROR);

    test()->call('POST', doordashWebhookUrl(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_DOORDASH_SIGNATURE' => $signature,
    ], $tampered)->assertStatus(400);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
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
        'delivery_status' => 'delivered',
        'created_at' => '2026-07-17T12:30:00.000Z',
    ]))->assertOk();
    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Delivered);

    postDoorDashWebhook(doordashWebhookPayload([
        'delivery_status' => 'created',
        'created_at' => '2026-07-17T12:00:00.000Z',
    ]))->assertOk();

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('applies a newer event', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(['delivery_status' => 'confirmed', 'created_at' => '2026-07-17T12:00:00.000Z']));
    postDoorDashWebhook(doordashWebhookPayload(['delivery_status' => 'picked_up', 'created_at' => '2026-07-17T12:10:00.000Z']));

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::PickedUp);
});

it('notes each status change on the order timeline, but not a repeat', function () {
    $assignment = seedDoorDashWebhookFixture();

    postDoorDashWebhook(doordashWebhookPayload(['delivery_status' => 'confirmed', 'created_at' => '2026-07-17T12:00:00.000Z']));
    postDoorDashWebhook(doordashWebhookPayload(['delivery_status' => 'confirmed', 'created_at' => '2026-07-17T12:05:00.000Z']));

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

    postDoorDashWebhook(doordashWebhookPayload(['delivery_status' => 'confirmed']))->assertOk();

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

    postDoorDashWebhook(doordashWebhookPayload(['delivery_status' => 'cancelled']))->assertOk();

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

    postDoorDashWebhook(doordashWebhookPayload(['delivery_status' => 'confirmed']))->assertOk();
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

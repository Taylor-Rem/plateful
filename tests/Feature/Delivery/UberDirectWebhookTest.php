<?php

use App\Enums\DeliveryProviderName;
use App\Enums\DeliveryStatus;
use App\Models\DeliveryAssignment;
use App\Models\DeliveryIntegration;
use App\Models\OrderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';

const UBER_WEBHOOK_KEY = 'whsec_uber_test_key';
const UBER_WEBHOOK_CUSTOMER = 'cust_hook_1';

function uberWebhookUrl(): string
{
    return 'http://admin.plateful.test/webhooks/uber';
}

/**
 * @param  array<string, mixed>  $payload
 */
function postUberWebhook(array $payload, ?string $key = UBER_WEBHOOK_KEY, ?string $header = 'x-uber-signature')
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = ['Content-Type' => 'application/json'];

    if ($key !== null && $header !== null) {
        $headers[$header] = hash_hmac('sha256', $body, $key);
    }

    return test()->call('POST', uberWebhookUrl(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        ...collect($headers)->mapWithKeys(fn ($v, $k) => [
            'HTTP_'.str_replace('-', '_', strtoupper($k)) => $v,
        ])->all(),
    ], $body);
}

/**
 * @return array<string, mixed>
 */
function uberWebhookPayload(array $overrides = [], array $dataOverrides = []): array
{
    return array_merge([
        'kind' => 'event.delivery_status',
        'id' => 'evt_'.fake()->uuid(),
        'delivery_id' => 'del_hook_1',
        'customer_id' => UBER_WEBHOOK_CUSTOMER,
        'status' => 'pickup',
        'created' => '2026-07-14T12:00:00.000Z',
        'live_mode' => false,
        'data' => array_merge([
            'tracking_url' => 'https://ubereats.com/track/abc',
            'fee' => 610,
            'courier' => ['name' => 'Sam', 'phone_number' => '+15555555557'],
        ], $dataOverrides),
    ], $overrides);
}

function seedUberWebhookFixture(string $status = 'pending'): DeliveryAssignment
{
    config(['platform.primary_domain' => 'plateful.test']);

    $restaurant = adminOrderRestaurant('hookco');
    DeliveryIntegration::factory()->create([
        'restaurant_id' => $restaurant->id,
        'customer_id' => UBER_WEBHOOK_CUSTOMER,
        'webhook_signing_key' => UBER_WEBHOOK_KEY,
    ]);

    $order = makeOrder($restaurant);

    return DeliveryAssignment::create([
        'order_id' => $order->id,
        'provider' => DeliveryProviderName::Uber,
        'external_id' => 'del_hook_1',
        'status' => DeliveryStatus::from($status === 'pending' ? 'pending' : $status),
    ]);
}

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
});

it('applies a correctly signed delivery status event', function () {
    $assignment = seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload())->assertOk();

    $fresh = $assignment->fresh();
    expect($fresh->status)->toBe(DeliveryStatus::DriverAssigned);
    expect($fresh->driver_name)->toBe('Sam');
    expect($fresh->driver_phone)->toBe('+15555555557');
    expect($fresh->tracking_url)->toBe('https://ubereats.com/track/abc');
    expect($fresh->actual_fee_cents)->toBe(610);
});

it('accepts the x-postmates-signature header too', function () {
    // Uber sends one or the other depending on event type; betting on a single
    // header name would silently drop half the traffic.
    $assignment = seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload(), header: 'x-postmates-signature')->assertOk();

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::DriverAssigned);
});

it('rejects an event signed with the wrong key', function () {
    $assignment = seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload(), key: 'not-the-real-key')->assertStatus(400);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('rejects an unsigned event', function () {
    $assignment = seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload(), key: null, header: null)->assertStatus(400);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('rejects a payload whose body was tampered with after signing', function () {
    $assignment = seedUberWebhookFixture();

    $signedBody = json_encode(uberWebhookPayload(), JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $signedBody, UBER_WEBHOOK_KEY);
    $tampered = json_encode(uberWebhookPayload(['status' => 'delivered']), JSON_THROW_ON_ERROR);

    test()->call('POST', uberWebhookUrl(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_UBER_SIGNATURE' => $signature,
    ], $tampered)->assertStatus(400);

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('verifies against the signing key of the restaurant the event claims', function () {
    // The signing key is per-restaurant, so a payload naming restaurant A but
    // signed with restaurant B's key must not be honoured.
    seedUberWebhookFixture();

    $other = adminOrderRestaurant('hookother');
    DeliveryIntegration::factory()->create([
        'restaurant_id' => $other->id,
        'customer_id' => 'cust_other',
        'webhook_signing_key' => 'whsec_other_key',
    ]);

    postUberWebhook(uberWebhookPayload(), key: 'whsec_other_key')->assertStatus(400);
});

it('rejects an event for a customer id we do not know', function () {
    seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload(['customer_id' => 'cust_nobody']))->assertStatus(400);
});

it('rejects when the restaurant has no signing key configured', function () {
    $restaurant = adminOrderRestaurant('hooknokey');
    DeliveryIntegration::factory()->create([
        'restaurant_id' => $restaurant->id,
        'customer_id' => UBER_WEBHOOK_CUSTOMER,
        'webhook_signing_key' => null,
    ]);

    postUberWebhook(uberWebhookPayload())->assertStatus(400);
});

it('drops a retried event older than one already applied', function () {
    $assignment = seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload([
        'status' => 'delivered',
        'created' => '2026-07-14T12:30:00.000Z',
    ]))->assertOk();
    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Delivered);

    // Uber retries at 10s/40s/100s/220s, so a stale `pending` can land after
    // `delivered`. It must not walk the status backwards.
    postUberWebhook(uberWebhookPayload([
        'status' => 'pending',
        'created' => '2026-07-14T12:00:00.000Z',
    ]))->assertOk();

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Delivered);
});

it('applies a newer event', function () {
    $assignment = seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload(['status' => 'pickup', 'created' => '2026-07-14T12:00:00.000Z']));
    postUberWebhook(uberWebhookPayload(['status' => 'dropoff', 'created' => '2026-07-14T12:10:00.000Z']));

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::PickedUp);
});

it('notes each status change on the order timeline, but not a repeat', function () {
    $assignment = seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload(['status' => 'pickup', 'created' => '2026-07-14T12:00:00.000Z']));
    postUberWebhook(uberWebhookPayload(['status' => 'pickup', 'created' => '2026-07-14T12:05:00.000Z']));

    $notes = OrderEvent::query()->where('order_id', $assignment->order_id)->get();
    expect($notes)->toHaveCount(1);
    expect($notes->first()->note)->toContain('driver_assigned');
});

it('acknowledges a delivery it has no record of so Uber stops retrying', function () {
    seedUberWebhookFixture();

    // 200, not 400: retrying will never conjure the assignment.
    postUberWebhook(uberWebhookPayload(['delivery_id' => 'del_unknown']))->assertOk();
});

it('acknowledges but ignores event kinds it does not handle', function () {
    $assignment = seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload(['kind' => 'event.refund_request']))->assertOk();

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Pending);
});

it('excludes the tip from actual_fee_cents on the webhook path too', function () {
    $assignment = seedUberWebhookFixture();
    $assignment->order->forceFill(['tip_cents' => 500])->save();

    // The webhook carries the same tip-inclusive fee the API does, so it needs
    // the same correction or the drift measurement is wrong depending on which
    // path happened to update it last.
    postUberWebhook(uberWebhookPayload(dataOverrides: ['fee' => 1032]))->assertOk();

    expect($assignment->fresh()->actual_fee_cents)->toBe(532);
});

it('maps a cancelled delivery through the webhook', function () {
    $assignment = seedUberWebhookFixture();

    postUberWebhook(uberWebhookPayload(['status' => 'canceled']))->assertOk();

    expect($assignment->fresh()->status)->toBe(DeliveryStatus::Cancelled);
});

it('is exempt from CSRF so Uber can actually reach it', function () {
    $assignment = seedUberWebhookFixture();

    // A 419 here would mean every real webhook silently fails in production.
    postUberWebhook(uberWebhookPayload())->assertOk();
});

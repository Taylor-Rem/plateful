<?php

use App\Models\Restaurant;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

require_once __DIR__.'/ApiHelpers.php';
require_once __DIR__.'/../../Storefront/CartTestHelpers.php';

/**
 * Partial-mock StripeConnectService for the PaymentIntent path. Every
 * money-moving method is listed so nothing unmocked can reach Stripe.
 * `$retrievedStatus` is what retrievePaymentIntent reports back — the
 * confirm endpoint's whole decision rests on it.
 */
function fakePaymentIntents(string $retrievedStatus = 'succeeded'): MockInterface
{
    config()->set('services.stripe.secret', 'sk_test_dummy');
    config()->set('services.stripe.key', 'pk_test_dummy');

    $mock = Mockery::mock(
        StripeConnectService::class.'[createPaymentIntent,retrievePaymentIntent,refundOrder,capturePayment,voidPayment]',
        [app(StripeClient::class)]
    );

    // Process-wide so a test that starts several checkouts (or re-fakes)
    // never reuses an intent id — pending_checkouts pins them unique.
    static $seq = 0;
    $mock->shouldReceive('createPaymentIntent')->andReturnUsing(function () use (&$seq) {
        $seq++;

        return PaymentIntent::constructFrom([
            'id' => 'pi_app_'.$seq,
            'client_secret' => 'pi_app_'.$seq.'_secret_xyz',
            'status' => 'requires_payment_method',
        ]);
    })->byDefault();

    $mock->shouldReceive('retrievePaymentIntent')->andReturnUsing(
        fn ($restaurant, $id) => PaymentIntent::constructFrom(['id' => $id, 'status' => $retrievedStatus]),
    )->byDefault();

    $mock->shouldReceive('refundOrder')->andReturn(Refund::constructFrom(['id' => 're_test']));
    $mock->shouldReceive('capturePayment')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_captured', 'status' => 'succeeded']));
    $mock->shouldReceive('voidPayment')->andReturn(PaymentIntent::constructFrom(['id' => 'pi_voided', 'status' => 'canceled']));

    app()->instance(StripeConnectService::class, $mock);

    return $mock;
}

function apiRestaurantBase(Restaurant $restaurant): string
{
    return API_BASE.'/restaurants/'.$restaurant->subdomain;
}

/**
 * Add the fixture's medium pepperoni pizza ($14 — both are defaults) to a guest cart and return
 * [response, cartToken].
 *
 * @param  array<string, mixed>  $fixture
 * @return array{0: TestResponse, 1: string}
 */
function addPepViaApi(array $fixture, ?string $token = null): array
{
    $headers = $token !== null ? ['X-Cart-Token' => $token] : [];

    $response = test()->withHeaders($headers)->postJson(
        apiRestaurantBase($fixture['restaurant']).'/cart/items/'.$fixture['item']->id,
        ['option_ids' => [$fixture['size_medium']->id, $fixture['top_pepperoni']->id]],
    );

    test()->flushHeaders();

    test()->flushHeaders();

    return [$response, (string) $response->json('cartToken')];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pickupCheckoutPayload(array $overrides = []): array
{
    return [
        'customer_name' => 'Ada Diner',
        'customer_email' => 'ada@example.com',
        'type' => 'pickup',
        ...$overrides,
    ];
}

/**
 * Post a signed Stripe webhook event the way the storefront suite does.
 *
 * @param  array<string, mixed>  $object
 */
function postStripeEvent(string $type, array $object): TestResponse
{
    $payload = json_encode(['id' => 'evt_'.uniqid(), 'object' => 'event', 'type' => $type, 'data' => ['object' => $object]]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_dummy');

    return test()->call(
        'POST',
        'http://admin.plateful.test/stripe/webhook',
        [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );
}

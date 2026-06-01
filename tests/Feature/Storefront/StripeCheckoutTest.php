<?php

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Order;
use App\Models\PendingCheckout;
use App\Models\Restaurant;
use App\Services\CartManager;
use App\Services\OrderTransition;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Stripe\Refund;
use Stripe\StripeClient;

require_once __DIR__.'/CartTestHelpers.php';
require_once __DIR__.'/CheckoutTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

function addPep($t, array $f): string
{
    $r = $f['restaurant'];
    $resp = $t->post("http://{$r->subdomain}.plateful.test/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]);

    return cartCookieFrom($resp);
}

it('rejects checkout when the restaurant is not Stripe-ready', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $r->forceFill(['stripe_account_status' => Restaurant::STRIPE_PENDING])->save();
    $cookie = addPep($this, $f);

    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ], ['Accept' => 'application/json'])
        ->assertStatus(422);

    expect(Order::count())->toBe(0);
    expect(PendingCheckout::count())->toBe(0);
});

it('computes the application fee from the food subtotal only', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    // $14 subtotal + tax + $5 tip → fee is 1% of 1400 = 14, NOT of the total.
    $r->update(['tax_rate_percent' => 8.875]);
    $cookie = addPep($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
            'tip_preset' => 'custom',
            'tip_custom_cents' => 500,
        ]);

    $order = payLatestCheckout();
    expect($order->application_fee_cents)->toBe(14)
        ->and($order->total_cents)->toBeGreaterThan(1400);
});

it('scales the application fee with a custom percent', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $r->forceFill(['application_fee_percent' => 1.5])->save();
    $cookie = addPep($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);

    // floor(1400 * 1.5 / 100) = 21
    expect(payLatestCheckout()->application_fee_cents)->toBe(21);
});

it('materializes the order from a checkout.session.completed webhook', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPep($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);

    expect(Order::count())->toBe(0);

    $sessionId = PendingCheckout::firstOrFail()->stripe_checkout_session_id;
    $payload = json_encode([
        'id' => 'evt_1',
        'object' => 'event',
        'type' => 'checkout.session.completed',
        'data' => ['object' => [
            'id' => $sessionId,
            'object' => 'checkout.session',
            'payment_intent' => 'pi_webhook',
            'payment_status' => 'paid',
        ]],
    ]);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_dummy');

    $this->call(
        'POST',
        'http://admin.plateful.test/stripe/webhook',
        [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        $payload,
    )->assertOk();

    $order = Order::firstOrFail();
    expect($order->stripe_checkout_session_id)->toBe($sessionId)
        ->and($order->stripe_payment_intent_id)->toBe('pi_webhook')
        ->and($order->status)->toBe(OrderStatus::Pending);
    expect(PendingCheckout::firstOrFail()->status)->toBe(PendingCheckout::STATUS_CONSUMED);
});

it('does not create a second order when webhook and return both fire', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPep($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);

    // Materialize twice (return handler + webhook).
    $first = payLatestCheckout();
    $second = payLatestCheckout();

    expect(Order::count())->toBe(1)
        ->and($second->id)->toBe($first->id);
});

it('returns from Stripe, materializes, and lands on the order page', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPep($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);

    $sessionId = PendingCheckout::firstOrFail()->stripe_checkout_session_id;

    $resp = $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->get("http://{$r->subdomain}.plateful.test/checkout/return?session_id={$sessionId}");

    $order = Order::firstOrFail();
    $resp->assertRedirect("http://{$r->subdomain}.plateful.test/orders/{$order->number}");
    $resp->assertCookie('plateful_recent_order');
});

it('full-refunds the charge and reverses the fee when a paid order is cancelled', function () {
    $f = cartFixture();
    $r = $f['restaurant'];
    $cookie = addPep($this, $f);

    fakeCheckoutSession();
    $this->withCookie(CartManager::COOKIE_NAME, $cookie)
        ->post("http://{$r->subdomain}.plateful.test/orders", [
            'customer_name' => 'A',
            'customer_email' => 'a@a.test',
            'type' => 'pickup',
        ]);
    $order = payLatestCheckout();

    /** @var MockInterface $connect */
    $connect = Mockery::mock(StripeConnectService::class, [app(StripeClient::class)])->makePartial();
    $connect->shouldReceive('refundOrder')->once()
        ->withArgs(fn (Order $o) => $o->id === $order->id)
        ->andReturn(Refund::constructFrom(['id' => 're_1']));
    app()->instance(StripeConnectService::class, $connect);

    app(OrderTransition::class)->apply($order->fresh()->load('restaurant'), OrderStatus::Cancelled, null);

    $order->refresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->refunded_at)->not->toBeNull()
        ->and($order->refunded_cents)->toBe($order->total_cents);
});

it('does not attempt a refund for an order with no payment', function () {
    $r = Restaurant::factory()->create();
    $order = Order::create([
        'restaurant_id' => $r->id,
        'customer_name' => 'A', 'customer_email' => 'a@a.test',
        'number' => 'AAA-AAAAA', 'status' => OrderStatus::Pending,
        'type' => OrderType::Pickup,
        'subtotal_cents' => 1000, 'tax_cents' => 0, 'tip_cents' => 0,
        'delivery_fee_cents' => 0, 'application_fee_cents' => 0, 'total_cents' => 1000,
        'placed_at' => now(),
    ]);

    $connect = Mockery::mock(StripeConnectService::class, [app(StripeClient::class)])->makePartial();
    $connect->shouldReceive('refundOrder')->never();
    app()->instance(StripeConnectService::class, $connect);

    app(OrderTransition::class)->apply($order, OrderStatus::Cancelled, null);

    expect($order->fresh()->refunded_at)->toBeNull();
});

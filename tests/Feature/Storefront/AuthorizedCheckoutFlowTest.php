<?php

use App\Enums\DeliveryFeeStrategy;
use App\Enums\DeliveryMode;
use App\Enums\PaymentState;
use App\Jobs\DispatchDeliveryForOrder;
use App\Jobs\ExpireAuthorizedDelivery;
use App\Jobs\PushOrderToPos;
use App\Models\DeliveryIntegration;
use App\Models\Order;
use App\Models\PosIntegration;
use App\Services\CartManager;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;

uses(RefreshDatabase::class);

require_once __DIR__.'/CartTestHelpers.php';
require_once __DIR__.'/CheckoutTestHelpers.php';
require_once __DIR__.'/../Delivery/DeliveryQuoteTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
    Bus::fake([PushOrderToPos::class, DispatchDeliveryForOrder::class, ExpireAuthorizedDelivery::class]);

    $f = cartFixture();
    $this->fixture = $f;
    $this->restaurant = $f['restaurant'];
    $this->restaurant->update([
        'delivery_enabled' => true,
        'delivery_mode' => DeliveryMode::ThirdParty,
        'delivery_fee_strategy' => DeliveryFeeStrategy::PassThrough,
        'tax_rate_percent' => 0,
    ]);

    DeliveryIntegration::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'customer_id' => 'cust_flow',
    ]);
});

function authHost(): string
{
    return 'http://'.test()->restaurant->subdomain.'.plateful.test';
}

function authCartCookie(): string
{
    $f = test()->fixture;

    return cartCookieFrom(test()->post(authHost()."/cart/items/{$f['item']->id}", [
        'option_ids' => [$f['size_medium']->id, $f['top_pepperoni']->id],
    ]));
}

/**
 * @return array<string, mixed>
 */
function authCheckoutBody(string $type = 'delivery'): array
{
    $body = [
        'customer_name' => 'Bob',
        'customer_email' => 'bob@example.test',
        'type' => $type,
        'tip_preset' => '0',
    ];

    if ($type === 'delivery') {
        $quote = makeDeliveryQuote(test()->restaurant, quoteAddress(), 799);
        $body['delivery_address'] = quoteAddress();
        $body['delivery_quote_token'] = $quote->token;
    }

    return $body;
}

it('holds the money instead of taking it on a courier-network delivery', function () {
    $captured = null;
    $mock = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $mock);
    $mock->shouldReceive('createCheckoutSession')->once()
        ->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;

            return Session::constructFrom(['id' => 'cs_1', 'url' => 'https://stripe.test/pay']);
        });

    $this->withCookie(CartManager::COOKIE_NAME, authCartCookie())
        ->post(authHost().'/orders', authCheckoutBody());

    // Uber only looks for a driver AFTER the delivery is created — which is
    // after payment. A hold is the only thing that can keep the promise.
    expect($captured[7])->toBeTrue();
});

it('takes the money outright on pickup, which depends on no courier', function () {
    $captured = null;
    $mock = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $mock);
    $mock->shouldReceive('createCheckoutSession')->once()
        ->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;

            return Session::constructFrom(['id' => 'cs_1', 'url' => 'https://stripe.test/pay']);
        });

    $this->withCookie(CartManager::COOKIE_NAME, authCartCookie())
        ->post(authHost().'/orders', authCheckoutBody('pickup'));

    // Auth/capture on a pickup order would hold a customer's funds for no
    // reason whatsoever.
    expect($captured[7])->toBeFalse();
});

it('takes the money outright on self-delivery', function () {
    test()->restaurant->update(['delivery_mode' => DeliveryMode::SelfDelivery]);

    $captured = null;
    $mock = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $mock);
    $mock->shouldReceive('createCheckoutSession')->once()
        ->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;

            return Session::constructFrom(['id' => 'cs_1', 'url' => 'https://stripe.test/pay']);
        });

    $this->withCookie(CartManager::COOKIE_NAME, authCartCookie())
        ->post(authHost().'/orders', [
            'customer_name' => 'Bob',
            'customer_email' => 'bob@example.test',
            'type' => 'delivery',
            'delivery_address' => quoteAddress(),
            'tip_preset' => '0',
        ]);

    // The restaurant's own driver is a promise it can already keep.
    expect($captured[7])->toBeFalse();
});

it('accepts an authorization on return, which reads as unpaid', function () {
    // The trap the plan called out: `payment_status` on a manual-capture
    // session is `unpaid`, so the old paid-only check would have bounced a
    // customer who had just successfully paid.
    fakeCheckoutSession(authorized: true);

    $this->withCookie(CartManager::COOKIE_NAME, authCartCookie())
        ->post(authHost().'/orders', authCheckoutBody());

    $response = $this->get(authHost().'/checkout/return?session_id=cs_test_1');

    $response->assertRedirect();
    $order = Order::withoutTenantScope()->latest('id')->firstOrFail();
    expect($order->payment_state)->toBe(PaymentState::Authorized);
    expect($order->authorized_at)->not->toBeNull();
    expect($order->captured_at)->toBeNull();
});

it('still bounces a genuinely abandoned checkout', function () {
    $mock = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $mock);
    $mock->shouldReceive('createCheckoutSession')->andReturn(
        Session::constructFrom(['id' => 'cs_test_1', 'url' => 'https://stripe.test/pay'])
    );
    // Never completed: unpaid AND not complete.
    $mock->shouldReceive('retrieveCheckoutSession')->andReturn(
        Session::constructFrom([
            'id' => 'cs_test_1', 'payment_status' => 'unpaid', 'status' => 'open', 'payment_intent' => 'pi_x',
        ])
    );

    $this->withCookie(CartManager::COOKIE_NAME, authCartCookie())
        ->post(authHost().'/orders', authCheckoutBody());

    $this->get(authHost().'/checkout/return?session_id=cs_test_1')
        ->assertRedirect(route('storefront.checkout.show'));

    expect(Order::withoutTenantScope()->count())->toBe(0);
});

it('rejects a completed session whose intent is not actually captureable', function () {
    $mock = Mockery::mock(StripeConnectService::class);
    app()->instance(StripeConnectService::class, $mock);
    $mock->shouldReceive('createCheckoutSession')->andReturn(
        Session::constructFrom(['id' => 'cs_test_1', 'url' => 'https://stripe.test/pay'])
    );
    $mock->shouldReceive('retrieveCheckoutSession')->andReturn(
        Session::constructFrom([
            'id' => 'cs_test_1', 'payment_status' => 'unpaid', 'status' => 'complete', 'payment_intent' => 'pi_x',
        ])
    );
    // Complete-but-unpaid is not proof of anything on its own; the intent is.
    $mock->shouldReceive('retrievePaymentIntent')->andReturn(
        PaymentIntent::constructFrom(['id' => 'pi_x', 'status' => 'requires_payment_method'])
    );

    $this->withCookie(CartManager::COOKIE_NAME, authCartCookie())
        ->post(authHost().'/orders', authCheckoutBody());

    $this->get(authHost().'/checkout/return?session_id=cs_test_1')
        ->assertRedirect(route('storefront.checkout.show'));

    expect(Order::withoutTenantScope()->count())->toBe(0);
});

it('holds the kitchen ticket while the order is only authorized', function () {
    PosIntegration::factory()->create(['restaurant_id' => test()->restaurant->id]);
    fakeCheckoutSession(authorized: true);

    $this->withCookie(CartManager::COOKIE_NAME, authCartCookie())
        ->post(authHost().'/orders', authCheckoutBody());
    payLatestCheckout(PaymentState::Authorized);

    // Cooking a meal for an order we may be about to void is worse than a
    // ticket that prints a minute late.
    Bus::assertNotDispatched(PushOrderToPos::class);
    // The delivery still dispatches — that is what finds the courier.
    Bus::assertDispatched(DispatchDeliveryForOrder::class);
    // And a deadline is armed, so a hung search can't strand the hold.
    Bus::assertDispatched(ExpireAuthorizedDelivery::class);
});

it('sends the ticket immediately on a captured pickup order', function () {
    PosIntegration::factory()->create(['restaurant_id' => test()->restaurant->id]);
    fakeCheckoutSession();

    $this->withCookie(CartManager::COOKIE_NAME, authCartCookie())
        ->post(authHost().'/orders', authCheckoutBody('pickup'));
    payLatestCheckout();

    // Nothing to wait for, so nothing is held back.
    Bus::assertDispatched(PushOrderToPos::class);
    Bus::assertNotDispatched(ExpireAuthorizedDelivery::class);
});

it('clamps the Stripe session so quote drift is bounded', function () {
    // The quote lives 15 minutes but Stripe sessions default to 24 hours, so
    // without this the fee could drift arbitrarily while the customer lingers.
    expect(StripeConnectService::SESSION_TTL_MINUTES)->toBe(30);
});

<?php

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\PendingCheckout;
use App\Services\OrderPlacement;
use App\Services\Stripe\StripeConnectService;
use Stripe\Checkout\Session;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeClient;

if (! function_exists('fakeCheckoutSession')) {
    /**
     * Replace StripeConnectService with a partial mock so the checkout flow
     * never hits the network. createCheckoutSession returns a fresh fake
     * session per call (unique id, so multiple orders in one test stay
     * distinct); retrieveCheckoutSession returns a paid session echoing the
     * requested id. refundOrder / capturePayment / voidPayment are no-ops.
     * All other methods stay real.
     *
     * Every money-moving method must be listed here: a partial mock leaves
     * anything unnamed REAL, so an unmocked capture would fire at Stripe from
     * the test suite.
     *
     * `$authorized` fakes the manual-capture path instead: Stripe completes such
     * a session with `payment_status: unpaid` and a PaymentIntent sitting in
     * `requires_capture`.
     */
    function fakeCheckoutSession(string $url = 'https://stripe.test/checkout', bool $authorized = false): void
    {
        config()->set('services.stripe.secret', 'sk_test_dummy');

        $mock = Mockery::mock(
            StripeConnectService::class.'[createCheckoutSession,retrieveCheckoutSession,retrievePaymentIntent,refundOrder,capturePayment,voidPayment]',
            [app(StripeClient::class)]
        );

        $seq = 0;
        $mock->shouldReceive('createCheckoutSession')->andReturnUsing(function () use (&$seq, $url) {
            $seq++;

            return Session::constructFrom(['id' => 'cs_test_'.$seq, 'url' => $url]);
        });

        $mock->shouldReceive('retrieveCheckoutSession')->andReturnUsing(
            function ($restaurant, $sessionId) use ($authorized) {
                return Session::constructFrom([
                    'id' => $sessionId,
                    // A manual-capture session completes as `unpaid` — that is
                    // a successful authorization, not an abandoned checkout.
                    'payment_status' => $authorized ? 'unpaid' : 'paid',
                    'status' => 'complete',
                    'payment_intent' => 'pi_'.$sessionId,
                ]);
            }
        );

        $mock->shouldReceive('retrievePaymentIntent')->andReturnUsing(
            function ($restaurant, $paymentIntentId) use ($authorized) {
                return PaymentIntent::constructFrom([
                    'id' => $paymentIntentId,
                    'status' => $authorized ? 'requires_capture' : 'succeeded',
                ]);
            }
        );

        $mock->shouldReceive('refundOrder')->andReturn(Refund::constructFrom(['id' => 're_test']));
        $mock->shouldReceive('capturePayment')->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_captured', 'status' => 'succeeded',
        ]));
        $mock->shouldReceive('voidPayment')->andReturn(PaymentIntent::constructFrom([
            'id' => 'pi_voided', 'status' => 'canceled',
        ]));

        app()->instance(StripeConnectService::class, $mock);
    }
}

if (! function_exists('payLatestCheckout')) {
    /**
     * Simulate a successful payment by materializing the most recent pending
     * checkout into a real Order (as the webhook / return handler would).
     *
     * Defaults to `Captured` — the outcome for pickup and self-delivery, and
     * for every order that predates auth/capture. Pass
     * `PaymentState::Authorized` for the courier-network path.
     */
    function payLatestCheckout(PaymentState $paymentState = PaymentState::Captured): Order
    {
        $pending = PendingCheckout::query()->latest('id')->firstOrFail();

        return app(OrderPlacement::class)->completeCheckout($pending, [
            'stripe_checkout_session_id' => $pending->stripe_checkout_session_id,
            'stripe_payment_intent_id' => 'pi_'.$pending->stripe_checkout_session_id,
            'payment_state' => $paymentState,
        ]);
    }
}

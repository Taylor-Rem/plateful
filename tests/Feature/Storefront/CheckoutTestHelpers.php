<?php

use App\Models\Order;
use App\Models\PendingCheckout;
use App\Services\OrderPlacement;
use App\Services\Stripe\StripeConnectService;
use Stripe\Checkout\Session;
use Stripe\Refund;
use Stripe\StripeClient;

if (! function_exists('fakeCheckoutSession')) {
    /**
     * Replace StripeConnectService with a partial mock so the checkout flow
     * never hits the network. createCheckoutSession returns a fresh fake
     * session per call (unique id, so multiple orders in one test stay
     * distinct); retrieveCheckoutSession returns a paid session echoing the
     * requested id. refundOrder is a no-op. All other methods stay real.
     */
    function fakeCheckoutSession(string $url = 'https://stripe.test/checkout'): void
    {
        config()->set('services.stripe.secret', 'sk_test_dummy');

        $mock = Mockery::mock(
            StripeConnectService::class.'[createCheckoutSession,retrieveCheckoutSession,refundOrder]',
            [app(StripeClient::class)]
        );

        $seq = 0;
        $mock->shouldReceive('createCheckoutSession')->andReturnUsing(function () use (&$seq, $url) {
            $seq++;

            return Session::constructFrom(['id' => 'cs_test_'.$seq, 'url' => $url]);
        });
        $mock->shouldReceive('retrieveCheckoutSession')->andReturnUsing(function ($restaurant, $sessionId) {
            return Session::constructFrom([
                'id' => $sessionId,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_'.$sessionId,
            ]);
        });
        $mock->shouldReceive('refundOrder')->andReturn(Refund::constructFrom(['id' => 're_test']));

        app()->instance(StripeConnectService::class, $mock);
    }
}

if (! function_exists('payLatestCheckout')) {
    /**
     * Simulate a successful payment by materializing the most recent pending
     * checkout into a real Order (as the webhook / return handler would).
     */
    function payLatestCheckout(): Order
    {
        $pending = PendingCheckout::query()->latest('id')->firstOrFail();

        return app(OrderPlacement::class)->completeCheckout($pending, [
            'stripe_checkout_session_id' => $pending->stripe_checkout_session_id,
            'stripe_payment_intent_id' => 'pi_'.$pending->stripe_checkout_session_id,
        ]);
    }
}

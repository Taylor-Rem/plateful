<?php

namespace App\Http\Controllers;

use App\Models\PendingCheckout;
use App\Models\Restaurant;
use App\Services\OrderPlacement;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Account;
use Stripe\Checkout\Session;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeConnectService $connect,
        private OrderPlacement $orders,
    ) {}

    /**
     * Receive Stripe Connect webhooks. Currently only syncs connected-account
     * readiness from `account.updated`; unknown events are acknowledged so
     * Stripe stops retrying.
     */
    public function __invoke(Request $request): Response
    {
        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                (string) config('services.stripe.webhook_secret'),
            );
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            return response('Invalid payload.', 400);
        }

        if ($event->type === 'account.updated') {
            $account = $event->data->object;

            if ($account instanceof Account) {
                $restaurant = Restaurant::query()
                    ->where('stripe_account_id', $account->id)
                    ->first();

                if ($restaurant) {
                    $this->connect->syncAccountStatus($restaurant, $account);
                }
            }
        }

        if ($event->type === 'checkout.session.completed') {
            $session = $event->data->object;

            if ($session instanceof Session) {
                $pending = PendingCheckout::query()
                    ->where('stripe_checkout_session_id', $session->id)
                    ->first();

                if ($pending) {
                    $this->orders->completeCheckout($pending, [
                        'stripe_checkout_session_id' => $session->id,
                        'stripe_payment_intent_id' => is_string($session->payment_intent) ? $session->payment_intent : null,
                    ]);
                }
            }
        }

        return response('', 200);
    }
}

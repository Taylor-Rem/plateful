<?php

namespace App\Http\Controllers;

use App\Enums\PaymentState;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\PendingCheckout;
use App\Models\Restaurant;
use App\Services\OrderPlacement;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\Checkout\Session;
use Stripe\Dispute;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Webhook;
use UnexpectedValueException;

class StripeWebhookController extends Controller
{
    public function __construct(
        private StripeConnectService $connect,
        private OrderPlacement $orders,
    ) {}

    /**
     * Receive Stripe Connect webhooks: connected-account readiness from
     * `account.updated`, order materialization from `checkout.session.completed`
     * (web) and `payment_intent.succeeded` / `payment_intent.amount_capturable_updated`
     * (app), and chargeback visibility from `charge.dispute.created`. Unknown events
     * are acknowledged so Stripe stops retrying.
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

        // App checkouts pay by PaymentIntent (no Checkout Session). `succeeded`
        // is a captured payment; `amount_capturable_updated` is a manual-capture
        // hold for courier delivery. Only intents a pending checkout was issued
        // for are materialized — the web flow's intents carry no such row and
        // are handled by checkout.session.completed above.
        if (in_array($event->type, ['payment_intent.succeeded', 'payment_intent.amount_capturable_updated'], true)) {
            $intent = $event->data->object;

            if ($intent instanceof PaymentIntent) {
                $pending = PendingCheckout::query()
                    ->where('stripe_payment_intent_id', $intent->id)
                    ->first();

                if ($pending) {
                    $this->orders->completeCheckout($pending, [
                        'stripe_payment_intent_id' => $intent->id,
                        'payment_state' => $event->type === 'payment_intent.succeeded'
                            ? PaymentState::Captured
                            : PaymentState::Authorized,
                    ]);
                }
            }
        }

        if ($event->type === 'charge.dispute.created') {
            $dispute = $event->data->object;

            if ($dispute instanceof Dispute) {
                $order = is_string($dispute->payment_intent)
                    ? Order::query()->where('stripe_payment_intent_id', $dispute->payment_intent)->first()
                    : null;

                if ($order) {
                    $amount = number_format($dispute->amount / 100, 2);
                    OrderEvent::note($order, "Payment disputed (chargeback): \${$amount}, reason \"{$dispute->reason}\". The restaurant must respond in its Stripe dashboard before the evidence deadline.");
                }

                // Error-level so it reaches monitoring — a dispute has a hard
                // response deadline and losing one by silence costs real money.
                Log::error('Stripe dispute received', [
                    'dispute_id' => $dispute->id,
                    'payment_intent' => $dispute->payment_intent,
                    'reason' => $dispute->reason,
                    'amount' => $dispute->amount,
                    'order_id' => $order?->id,
                ]);
            }
        }

        return response('', 200);
    }
}

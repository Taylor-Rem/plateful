<?php

namespace App\Services\Stripe;

use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Log;
use Stripe\Account;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\PaymentIntent;
use Stripe\Payout;
use Stripe\Refund;
use Stripe\StripeClient;

class StripeConnectService
{
    public function __construct(private StripeClient $stripe) {}

    /**
     * Stripe's minimum session lifetime. Used to bound how long a delivery
     * quote can drift while the customer sits on Stripe's hosted page: the
     * quote lives 15 minutes and this is the tightest lid Stripe allows, so the
     * exposure is bounded rather than the 24h default.
     */
    public const SESSION_TTL_MINUTES = 30;

    /**
     * Create a Stripe-hosted Checkout Session as a DIRECT charge on the
     * restaurant's connected account, taking Plateful's application fee.
     *
     * `$manualCapture` places a HOLD instead of taking the money — used when
     * fulfilment depends on a courier nobody has found yet. The session then
     * completes with `payment_status: unpaid` and a PaymentIntent in
     * `requires_capture`; see {@see capturePayment()} / {@see voidPayment()}.
     *
     * @param  array<string, string>  $urls  ['success_url', 'cancel_url']
     */
    public function createCheckoutSession(
        Restaurant $restaurant,
        int $totalCents,
        int $applicationFeeCents,
        string $customerEmail,
        array $urls,
        string $idempotencyKey,
        int $pendingCheckoutId,
        bool $manualCapture = false,
    ): Session {
        $paymentIntentData = ['application_fee_amount' => $applicationFeeCents];

        if ($manualCapture) {
            $paymentIntentData['capture_method'] = 'manual';
        }

        return $this->withSuppressedStripeNotices(fn () => $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => 'Order at '.$restaurant->name],
                    'unit_amount' => $totalCents,
                ],
                'quantity' => 1,
            ]],
            'payment_intent_data' => $paymentIntentData,
            'customer_email' => $customerEmail,
            'success_url' => $urls['success_url'],
            'cancel_url' => $urls['cancel_url'],
            'expires_at' => now()->addMinutes(self::SESSION_TTL_MINUTES)->timestamp,
            'metadata' => ['pending_checkout_id' => (string) $pendingCheckoutId],
        ], [
            'stripe_account' => $restaurant->stripe_account_id,
            'idempotency_key' => $idempotencyKey,
        ]));
    }

    /**
     * Turn a hold into money. Called once a courier is actually confirmed —
     * the first moment anyone can honestly say the delivery will happen.
     */
    public function capturePayment(Order $order): PaymentIntent
    {
        return $this->withSuppressedStripeNotices(fn () => $this->stripe->paymentIntents->capture(
            (string) $order->stripe_payment_intent_id,
            [],
            ['stripe_account' => $order->restaurant->stripe_account_id],
        ));
    }

    /**
     * Release a hold without ever charging.
     *
     * Stripe calls this cancelling the PaymentIntent, and it is NOT a refund: a
     * refund requires a captured charge and Stripe rejects one on an
     * uncaptured intent. The customer sees a pending hold disappear rather than
     * a charge followed by a refund — which is the entire point of §8.
     */
    public function voidPayment(Order $order): PaymentIntent
    {
        return $this->withSuppressedStripeNotices(fn () => $this->stripe->paymentIntents->cancel(
            (string) $order->stripe_payment_intent_id,
            [],
            ['stripe_account' => $order->restaurant->stripe_account_id],
        ));
    }

    public function retrievePaymentIntent(Restaurant $restaurant, string $paymentIntentId): PaymentIntent
    {
        return $this->withSuppressedStripeNotices(fn () => $this->stripe->paymentIntents->retrieve(
            $paymentIntentId,
            [],
            ['stripe_account' => $restaurant->stripe_account_id],
        ));
    }

    /**
     * List recent payouts on the restaurant's connected account.
     *
     * @param  array<string, mixed>  $params  e.g. ['limit' => 20, 'starting_after' => 'po_…']
     * @return Collection<Payout>
     */
    public function listPayouts(Restaurant $restaurant, array $params = []): Collection
    {
        return $this->withSuppressedStripeNotices(fn () => $this->stripe->payouts->all(
            $params,
            ['stripe_account' => $restaurant->stripe_account_id],
        ));
    }

    public function retrieveCheckoutSession(Restaurant $restaurant, string $sessionId): Session
    {
        return $this->withSuppressedStripeNotices(fn () => $this->stripe->checkout->sessions->retrieve(
            $sessionId,
            [],
            ['stripe_account' => $restaurant->stripe_account_id],
        ));
    }

    /**
     * Full refund of an order's charge, reversing Plateful's application fee
     * so the restaurant gets the whole amount back.
     */
    public function refundOrder(Order $order): Refund
    {
        return $this->withSuppressedStripeNotices(fn () => $this->stripe->refunds->create([
            'payment_intent' => $order->stripe_payment_intent_id,
            'refund_application_fee' => true,
        ], [
            'stripe_account' => $order->restaurant->stripe_account_id,
        ]));
    }

    /**
     * Partial refund of an order's charge, returning `$applicationFeeReversal`
     * of Plateful's fee separately (DoorDash plan Session 5).
     *
     * A partial cancel refunds only the recoverable slice — the food per policy,
     * the delivery only when the courier network gave its fee back. `Refund`'s
     * boolean `refund_application_fee` would return the WHOLE fee, which under
     * central billing includes the courier passthrough Plateful already paid
     * DoorDash — so the fee is reversed by an explicit amount instead, via a
     * separate Application Fee Refund. `$applicationFeeReversal` may be 0, in
     * which case only the customer is refunded and no fee is reversed.
     */
    public function refundOrderPartial(Order $order, int $customerRefundCents, int $applicationFeeReversalCents): Refund
    {
        return $this->withSuppressedStripeNotices(function () use ($order, $customerRefundCents, $applicationFeeReversalCents): Refund {
            $refund = $this->stripe->refunds->create([
                'payment_intent' => $order->stripe_payment_intent_id,
                'amount' => $customerRefundCents,
                'refund_application_fee' => false,
            ], [
                'stripe_account' => $order->restaurant->stripe_account_id,
            ]);

            if ($applicationFeeReversalCents > 0) {
                $applicationFeeId = $this->applicationFeeIdFor($order);

                if ($applicationFeeId !== null) {
                    $this->stripe->applicationFees->createRefund(
                        $applicationFeeId,
                        ['amount' => $applicationFeeReversalCents],
                    );
                }
            }

            return $refund;
        });
    }

    /**
     * The `fee_…` id of the application fee Stripe took on this order's charge,
     * read off the PaymentIntent's charge. Needed to reverse the fee by an
     * explicit amount. Null if the charge has no application fee recorded.
     */
    private function applicationFeeIdFor(Order $order): ?string
    {
        $intent = $this->stripe->paymentIntents->retrieve(
            (string) $order->stripe_payment_intent_id,
            ['expand' => ['latest_charge']],
            ['stripe_account' => $order->restaurant->stripe_account_id],
        );

        $charge = $intent->latest_charge;
        $fee = is_object($charge) ? ($charge->application_fee ?? null) : null;

        if ($fee === null) {
            return null;
        }

        return is_object($fee) ? $fee->id : (string) $fee;
    }

    /**
     * Create an Express connected account for the restaurant and persist its
     * id. Returns the `acct_…` id.
     */
    public function createExpressAccount(Restaurant $restaurant): string
    {
        $account = $this->withSuppressedStripeNotices(fn () => $this->stripe->accounts->create([
            'type' => 'express',
            'country' => (string) config('services.stripe.connect_country', 'US'),
            'email' => $restaurant->email,
            'business_profile' => [
                'name' => $restaurant->name,
                'url' => $restaurant->publicUrl(),
            ],
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true],
            ],
            'metadata' => [
                'restaurant_id' => (string) $restaurant->id,
            ],
        ]));

        $restaurant->forceFill([
            'stripe_account_id' => $account->id,
            'stripe_account_status' => Restaurant::STRIPE_PENDING,
        ])->save();

        return $account->id;
    }

    /**
     * Generate a Stripe-hosted onboarding link the owner is redirected to.
     */
    public function createAccountLink(Restaurant $restaurant, string $returnUrl, string $refreshUrl): string
    {
        $link = $this->withSuppressedStripeNotices(fn () => $this->stripe->accountLinks->create([
            'account' => $restaurant->stripe_account_id,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type' => 'account_onboarding',
        ]));

        return $link->url;
    }

    public function retrieveAccount(string $stripeAccountId): Account
    {
        return $this->withSuppressedStripeNotices(fn () => $this->stripe->accounts->retrieve($stripeAccountId));
    }

    /**
     * Express Dashboard login link, used by the owner to update bank info
     * after onboarding.
     */
    public function createDashboardLink(Restaurant $restaurant): string
    {
        $link = $this->withSuppressedStripeNotices(fn () => $this->stripe->accounts->createLoginLink($restaurant->stripe_account_id));

        return $link->url;
    }

    /**
     * Read the readiness flags off a Stripe Account object and persist the
     * mapped status onto the restaurant. Used by both the onboarding return
     * handler and the `account.updated` webhook.
     */
    public function syncAccountStatus(Restaurant $restaurant, Account $account): void
    {
        $status = self::statusFor(
            (bool) ($account->charges_enabled ?? false),
            (bool) ($account->details_submitted ?? false),
        );

        $restaurant->forceFill(['stripe_account_status' => $status])->save();
    }

    /**
     * Map Stripe's readiness flags onto Plateful's small status vocabulary.
     */
    public static function statusFor(bool $chargesEnabled, bool $detailsSubmitted): string
    {
        return match (true) {
            $chargesEnabled => Restaurant::STRIPE_ENABLED,
            $detailsSubmitted => Restaurant::STRIPE_RESTRICTED,
            default => Restaurant::STRIPE_PENDING,
        };
    }

    /**
     * Run a StripeClient SDK call with informational Stripe notices made
     * non-fatal. stripe-php emits `Stripe-Notice` response headers (e.g. an
     * Accounts v2 recommendation) via trigger_error(E_USER_WARNING), which
     * Laravel's HandleExceptions would otherwise promote to a fatal
     * ErrorException. We log the notice and swallow it, leaving every other
     * error level — and anything raised outside this call — untouched.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function withSuppressedStripeNotices(callable $fn): mixed
    {
        set_error_handler(function (int $level, string $message): bool {
            Log::warning('[stripe-notice] '.$message);

            return true;
        }, E_USER_WARNING);

        try {
            return $fn();
        } finally {
            restore_error_handler();
        }
    }
}

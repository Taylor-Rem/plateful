<?php

namespace App\Services\Stripe;

use App\Models\Order;
use App\Models\Restaurant;
use Stripe\Account;
use Stripe\Checkout\Session;
use Stripe\Collection;
use Stripe\Payout;
use Stripe\Refund;
use Stripe\StripeClient;

class StripeConnectService
{
    public function __construct(private StripeClient $stripe) {}

    /**
     * Create a Stripe-hosted Checkout Session as a DIRECT charge on the
     * restaurant's connected account, taking Plateful's application fee.
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
    ): Session {
        return $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => ['name' => 'Order at '.$restaurant->name],
                    'unit_amount' => $totalCents,
                ],
                'quantity' => 1,
            ]],
            'payment_intent_data' => [
                'application_fee_amount' => $applicationFeeCents,
            ],
            'customer_email' => $customerEmail,
            'success_url' => $urls['success_url'],
            'cancel_url' => $urls['cancel_url'],
            'metadata' => ['pending_checkout_id' => (string) $pendingCheckoutId],
        ], [
            'stripe_account' => $restaurant->stripe_account_id,
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /**
     * List recent payouts on the restaurant's connected account.
     *
     * @param  array<string, mixed>  $params  e.g. ['limit' => 20, 'starting_after' => 'po_…']
     * @return Collection<Payout>
     */
    public function listPayouts(Restaurant $restaurant, array $params = []): Collection
    {
        return $this->stripe->payouts->all(
            $params,
            ['stripe_account' => $restaurant->stripe_account_id],
        );
    }

    public function retrieveCheckoutSession(Restaurant $restaurant, string $sessionId): Session
    {
        return $this->stripe->checkout->sessions->retrieve(
            $sessionId,
            [],
            ['stripe_account' => $restaurant->stripe_account_id],
        );
    }

    /**
     * Full refund of an order's charge, reversing Plateful's application fee
     * so the restaurant gets the whole amount back.
     */
    public function refundOrder(Order $order): Refund
    {
        return $this->stripe->refunds->create([
            'payment_intent' => $order->stripe_payment_intent_id,
            'refund_application_fee' => true,
        ], [
            'stripe_account' => $order->restaurant->stripe_account_id,
        ]);
    }

    /**
     * Create an Express connected account for the restaurant and persist its
     * id. Returns the `acct_…` id.
     */
    public function createExpressAccount(Restaurant $restaurant): string
    {
        $account = $this->stripe->accounts->create([
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
        ]);

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
        $link = $this->stripe->accountLinks->create([
            'account' => $restaurant->stripe_account_id,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type' => 'account_onboarding',
        ]);

        return $link->url;
    }

    public function retrieveAccount(string $stripeAccountId): Account
    {
        return $this->stripe->accounts->retrieve($stripeAccountId);
    }

    /**
     * Express Dashboard login link, used by the owner to update bank info
     * after onboarding.
     */
    public function createDashboardLink(Restaurant $restaurant): string
    {
        $link = $this->stripe->accounts->createLoginLink($restaurant->stripe_account_id);

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
}

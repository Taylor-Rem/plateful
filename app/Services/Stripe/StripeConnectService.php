<?php

namespace App\Services\Stripe;

use App\Models\Restaurant;
use Stripe\Account;
use Stripe\StripeClient;

class StripeConnectService
{
    public function __construct(private StripeClient $stripe) {}

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

<?php

namespace App\Services;

use App\Enums\MarketingChannel;
use App\Enums\MarketingConsentAction;
use App\Enums\MarketingConsentSource;
use App\Models\MarketingConsentEvent;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Per-restaurant email marketing consent (campaigns plan, Phase 1). Consent
 * state lives on the restaurant_customer pivot; every change appends a
 * MarketingConsentEvent carrying the exact consent text shown.
 */
class MarketingConsentService
{
    /**
     * The exact opt-in label rendered at checkout and on the account page.
     * Persisted as the consent_text_snapshot — keep in sync with the UI.
     */
    public function optInText(Restaurant $restaurant): string
    {
        return "Email me offers and news from {$restaurant->name}.";
    }

    /**
     * Idempotent: an already-eligible customer (e.g. a replayed checkout
     * materialization) neither re-stamps the pivot nor duplicates the event.
     */
    public function optInEmail(
        User $user,
        Restaurant $restaurant,
        MarketingConsentSource $source,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $pivot = RestaurantCustomer::query()->firstOrCreate([
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
        ]);

        if ($pivot->isEmailOptedIn()) {
            return;
        }

        // Re-opt-in after an opt-out clears opted_out_at and re-stamps opted_in_at.
        $pivot->forceFill([
            'marketing_email_opted_in_at' => now(),
            'marketing_email_opted_out_at' => null,
        ])->save();

        $this->recordEvent($user, $restaurant, MarketingConsentAction::OptedIn, $source, $ip, $userAgent, $this->optInText($restaurant));
    }

    public function optOutEmail(
        User $user,
        Restaurant $restaurant,
        MarketingConsentSource $source,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $pivot = RestaurantCustomer::query()->firstOrCreate([
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
        ]);

        if ($pivot->marketing_email_opted_out_at !== null) {
            return;
        }

        $pivot->forceFill(['marketing_email_opted_out_at' => now()])->save();

        $this->recordEvent(
            $user,
            $restaurant,
            MarketingConsentAction::OptedOut,
            $source,
            $ip,
            $userAgent,
            "Unsubscribed from marketing emails from {$restaurant->name}.",
        );
    }

    /**
     * Login-free unsubscribe link for a customer at a restaurant. The signature
     * covers the relative path + params (the storefront answers on subdomains
     * and custom domains), and the restaurant id is inside the signature so a
     * link can never opt a customer out anywhere but where it was issued for.
     */
    public function unsubscribeUrl(User $user, Restaurant $restaurant): string
    {
        $relative = URL::signedRoute('storefront.marketing.unsubscribe', [
            'user' => $user->id,
            'restaurant' => $restaurant->id,
        ], absolute: false);

        return $restaurant->publicUrl().$relative;
    }

    protected function recordEvent(
        User $user,
        Restaurant $restaurant,
        MarketingConsentAction $action,
        MarketingConsentSource $source,
        ?string $ip,
        ?string $userAgent,
        string $consentText,
    ): void {
        MarketingConsentEvent::create([
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'channel' => MarketingChannel::Email,
            'action' => $action,
            'source' => $source,
            'ip' => $ip,
            'user_agent' => $userAgent !== null ? mb_substr($userAgent, 0, 500) : null,
            'consent_text_snapshot' => $consentText,
        ]);
    }
}

<?php

namespace App\Services\Delivery;

use App\Contracts\DeliveryProvider;
use App\Enums\DeliveryFallbackAction;
use App\Enums\DeliveryMode;
use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Log;
use Throwable;

class DeliveryDispatcher
{
    /**
     * @param  array<string, DeliveryProvider>  $providers  keyed by DeliveryProviderName value
     */
    public function __construct(protected array $providers) {}

    /**
     * Resolve the ordered list of provider names to try for this restaurant.
     *
     * @return array<int, DeliveryProviderName>
     */
    public function providerChainFor(Restaurant $restaurant): array
    {
        if (! $restaurant->delivery_enabled) {
            return [];
        }

        if ($restaurant->delivery_mode === DeliveryMode::SelfDelivery) {
            return [DeliveryProviderName::Self];
        }

        // Default to the providers that actually have an adapter registered.
        // This previously defaulted to ['doordash', 'uber'], which meant any
        // restaurant switched to third-party mode got `provider_unsupported`
        // and a permanently failed job, because no DoorDash adapter exists.
        // Add 'doordash' here when §9 lands, not before.
        $priority = $restaurant->delivery_provider_priority ?: ['uber'];
        $chain = [];
        foreach ($priority as $name) {
            $enum = DeliveryProviderName::tryFrom((string) $name);
            if ($enum !== null) {
                $chain[] = $enum;
            }
        }

        return $chain;
    }

    public function quote(DeliveryQuoteRequest $request): DeliveryQuote
    {
        $chain = $this->providerChainFor($request->restaurant);
        if ($chain === []) {
            throw DeliveryProviderException::notConfigured('any');
        }

        $lastError = null;
        foreach ($chain as $providerName) {
            $provider = $this->providerFor($providerName, $request->restaurant);
            if ($provider === null) {
                continue;
            }
            try {
                return $provider->quote($request);
            } catch (Throwable $e) {
                $lastError = $e;
                Log::warning('Delivery quote failed', [
                    'provider' => $providerName->value,
                    'restaurant_id' => $request->restaurant->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw $lastError ?? DeliveryProviderException::driverNotAvailable('chain');
    }

    public function dispatch(Order $order): DeliveryDispatchResult
    {
        $restaurant = $order->restaurant;
        $chain = $this->providerChainFor($restaurant);
        $attempted = [];

        if ($chain === []) {
            return DeliveryDispatchResult::failed($attempted, 'delivery_not_configured');
        }

        $fallbackAction = $restaurant->delivery_fallback_action ?? DeliveryFallbackAction::TryNextProvider;

        $lastError = null;
        foreach ($chain as $i => $providerName) {
            $attempted[] = $providerName->value;
            $provider = $this->providerFor($providerName, $restaurant);
            if ($provider === null) {
                $lastError = 'provider_unsupported';

                continue;
            }

            try {
                $quote = $this->quoteForDispatch($provider, $order, $providerName);
                $assignment = $provider->create($order, $quote);
                $order->forceFill(['delivery_assignment_id' => $assignment->id])->save();

                return DeliveryDispatchResult::ok($assignment, $providerName, $attempted);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                Log::warning('Delivery dispatch attempt failed', [
                    'provider' => $providerName->value,
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);

                if ($fallbackAction !== DeliveryFallbackAction::TryNextProvider) {
                    break;
                }
            }
        }

        return DeliveryDispatchResult::failed($attempted, $lastError ?? 'unknown');
    }

    protected function providerFor(DeliveryProviderName $name, Restaurant $restaurant): ?DeliveryProvider
    {
        $provider = $this->providers[$name->value] ?? null;
        if ($provider === null) {
            return null;
        }

        return $provider->supports($restaurant) ? $provider : null;
    }

    /**
     * The quote to create the delivery from.
     *
     * Prefers the one taken at checkout — the customer was charged from it, and
     * replaying it keeps the price Uber honours identical to the price the
     * customer saw. It is only usable for 15 minutes though, and the customer
     * may have lingered on Stripe's hosted page, so an expired or absent quote
     * falls back to a fresh one. The restaurant carries any difference; that is
     * the drift §0 accepts, and `quote_fee_cents` vs `actual_fee_cents` is what
     * measures it.
     */
    protected function quoteForDispatch(
        DeliveryProvider $provider,
        Order $order,
        DeliveryProviderName $providerName,
    ): DeliveryQuote {
        $stored = $order->deliveryQuote();

        if ($stored !== null
            && ! $stored->isExpired()
            && $stored->provider === $providerName
            && $stored->external_quote_id !== null) {
            return $stored->toValueObject();
        }

        return $provider->quote($this->quoteRequestFromOrder($order));
    }

    protected function quoteRequestFromOrder(Order $order): DeliveryQuoteRequest
    {
        return new DeliveryQuoteRequest(
            restaurant: $order->restaurant,
            dropoffAddress: (array) ($order->delivery_address ?? []),
            subtotalCents: (int) $order->subtotal_cents,
            tipCents: (int) $order->tip_cents,
            customerName: $order->customer_name,
            customerPhone: $order->customer_phone,
            order: $order,
        );
    }
}

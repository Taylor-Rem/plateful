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

        $priority = $restaurant->delivery_provider_priority ?: ['doordash', 'uber'];
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
                $request = $this->quoteRequestFromOrder($order);
                $quote = $provider->quote($request);
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

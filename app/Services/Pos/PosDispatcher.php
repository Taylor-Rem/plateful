<?php

namespace App\Services\Pos;

use App\Contracts\PosProvider;
use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Exceptions\PosTokenExpiredException;
use App\Models\Order;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Log;
use Throwable;

class PosDispatcher
{
    /**
     * @param  array<string, PosProvider>  $providers  keyed by PosProviderName value
     */
    public function __construct(protected array $providers) {}

    /**
     * The connected integration for this restaurant, if any. Unscoped so it
     * resolves inside queue workers, where no tenant is bound.
     */
    public function integrationFor(Restaurant $restaurant): ?PosIntegration
    {
        return PosIntegration::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', PosIntegrationStatus::Connected->value)
            ->first();
    }

    public function shouldPush(Restaurant $restaurant): bool
    {
        return $this->integrationFor($restaurant) !== null;
    }

    public function dispatch(Order $order): PosPushResult
    {
        $restaurant = $order->restaurant;
        $integration = $this->integrationFor($restaurant);

        if ($integration === null) {
            return PosPushResult::failed(null, 'not_configured');
        }

        $provider = $this->providerFor($integration->provider, $restaurant);

        if ($provider === null) {
            return PosPushResult::failed($integration->provider, 'provider_unavailable');
        }

        try {
            return $provider->pushOrder($order, $integration);
        } catch (PosTokenExpiredException $e) {
            $integration->forceFill([
                'status' => PosIntegrationStatus::TokenExpired,
                'last_error' => $e->getMessage(),
            ])->save();

            return PosPushResult::failed($integration->provider, 'token_expired', tokenExpired: true);
        } catch (Throwable $e) {
            Log::warning('POS push attempt failed', [
                'provider' => $integration->provider->value,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return PosPushResult::failed($integration->provider, $e->getMessage());
        }
    }

    protected function providerFor(PosProviderName $name, Restaurant $restaurant): ?PosProvider
    {
        $provider = $this->providers[$name->value] ?? null;
        if ($provider === null) {
            return null;
        }

        return $provider->supports($restaurant) ? $provider : null;
    }
}

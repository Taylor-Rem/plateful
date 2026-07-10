<?php

namespace App\Jobs;

use App\Enums\OrderType;
use App\Exceptions\DeliveryProviderException;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\Delivery\DeliveryDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dispatch a paid delivery order to the restaurant's delivery provider chain.
 * Holds the order id (not the model) because queue workers run without a
 * bound tenant, so all lookups must bypass the tenant scope.
 */
class DispatchDeliveryForOrder implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $orderId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(DeliveryDispatcher $dispatcher): void
    {
        $order = Order::withoutTenantScope()
            ->with(['restaurant'])
            ->find($this->orderId);

        if (! $order || $order->type !== OrderType::Delivery || $order->delivery_assignment_id !== null) {
            return;
        }

        if ($dispatcher->providerChainFor($order->restaurant) === []) {
            return;
        }

        $result = $dispatcher->dispatch($order);

        if ($result->success) {
            OrderEvent::note($order, "Delivery dispatched via {$result->provider->value}");

            return;
        }

        OrderEvent::note($order, "Delivery dispatch attempt {$this->attempts()} failed: {$result->failureReason}");
        Log::warning('Delivery dispatch attempt failed, will retry', [
            'order_id' => $order->id,
            'attempt' => $this->attempts(),
            'error' => $result->failureReason,
        ]);

        throw DeliveryProviderException::driverNotAvailable(implode(',', $result->attemptedProviders) ?: 'any');
    }

    public function failed(?Throwable $exception): void
    {
        $order = Order::withoutTenantScope()->find($this->orderId);

        if (! $order || $order->delivery_assignment_id !== null) {
            return;
        }

        OrderEvent::note($order, 'Delivery dispatch permanently failed: '.($exception?->getMessage() ?? 'unknown error'));
        Log::error('Delivery dispatch permanently failed', [
            'order_id' => $order->id,
            'restaurant_id' => $order->restaurant_id,
            'error' => $exception?->getMessage(),
        ]);
    }
}

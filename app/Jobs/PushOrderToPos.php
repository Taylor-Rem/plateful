<?php

namespace App\Jobs;

use App\Exceptions\PosProviderException;
use App\Exceptions\PosTokenExpiredException;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Services\Pos\PosDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Push a paid order to the restaurant's POS. Holds the order id (not the
 * model) because queue workers run without a bound tenant, so all lookups
 * must bypass the tenant scope.
 */
class PushOrderToPos implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public int $orderId) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 3600];
    }

    public function handle(PosDispatcher $dispatcher): void
    {
        $order = Order::withoutTenantScope()
            ->with(['restaurant', 'items'])
            ->find($this->orderId);

        if (! $order || $order->pos_ticket_id !== null || $order->pos_push_failed_at !== null) {
            return;
        }

        if (! $dispatcher->shouldPush($order->restaurant)) {
            return;
        }

        $result = $dispatcher->dispatch($order);

        if ($result->success) {
            $order->forceFill([
                'pos_provider' => $result->provider,
                'pos_ticket_id' => $result->ticketId,
                'pos_pushed_at' => now(),
            ])->save();

            OrderEvent::note($order, "POS push succeeded ({$result->provider->value}), ticket {$result->ticketId}");

            return;
        }

        if ($result->failureReason === 'not_configured') {
            return;
        }

        $providerName = $result->provider?->value ?? 'unknown';

        if ($result->tokenExpired) {
            OrderEvent::note($order, "POS push failed ({$providerName}): access token expired, reconnect required");
            Log::error('POS integration token expired', [
                'order_id' => $order->id,
                'restaurant_id' => $order->restaurant_id,
                'provider' => $providerName,
            ]);
            $this->fail(PosTokenExpiredException::for($result->provider));

            return;
        }

        OrderEvent::note($order, "POS push attempt {$this->attempts()} failed ({$providerName}): {$result->failureReason}");
        Log::warning('POS push attempt failed, will retry', [
            'order_id' => $order->id,
            'attempt' => $this->attempts(),
            'provider' => $providerName,
            'error' => $result->failureReason,
        ]);

        throw PosProviderException::pushFailed((string) $result->failureReason);
    }

    public function failed(?Throwable $exception): void
    {
        $order = Order::withoutTenantScope()->find($this->orderId);

        if (! $order || $order->pos_ticket_id !== null) {
            return;
        }

        $order->forceFill(['pos_push_failed_at' => now()])->save();

        OrderEvent::note($order, 'POS push permanently failed: '.($exception?->getMessage() ?? 'unknown error'));
        Log::error('POS push permanently failed', [
            'order_id' => $order->id,
            'restaurant_id' => $order->restaurant_id,
            'error' => $exception?->getMessage(),
        ]);
    }
}

<?php

namespace App\Jobs;

use App\Enums\PaymentState;
use App\Models\Order;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\DeliverySettlement;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The backstop for an authorized delivery that never found a courier.
 *
 * The failure mode worth designing for is not the slow courier — it is the
 * search that hangs. Without this, such an order strands forever: no ticket in
 * the kitchen, no charge taken, and a hold sitting on the customer's card until
 * the bank drops it a week later. Nobody would find out.
 *
 * So this fails CLOSED. If, by the deadline, no courier has been confirmed, the
 * hold is released and the order is cancelled. It races the status webhook by
 * design; {@see DeliverySettlement} is idempotent on `payment_state`, so
 * whichever arrives first wins and the other no-ops.
 *
 * Holds the order id (not the model) because queue workers run without a bound
 * tenant, so lookups must bypass the tenant scope.
 */
class ExpireAuthorizedDelivery implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $orderId) {}

    /**
     * How long to let Uber look before giving up.
     *
     * Generous: courier assignment usually resolves in a couple of minutes, and
     * voiding a delivery that was about to be fine is a worse error than making
     * the customer wait a little longer to hear bad news.
     */
    public static function deadlineMinutes(): int
    {
        return (int) config('platform.delivery.courier_deadline_minutes', 10);
    }

    public function handle(DeliverySettlement $settlement, DeliveryDispatcher $dispatcher): void
    {
        $order = Order::withoutTenantScope()
            ->with(['restaurant', 'deliveryAssignment'])
            ->find($this->orderId);

        // Already settled — the webhook beat us here, which is the happy path.
        if (! $order || $order->payment_state !== PaymentState::Authorized) {
            return;
        }

        $assignment = $order->deliveryAssignment;

        // Ask the provider directly before giving up. The webhook is the fast
        // path, not a dependency: a missing webhook — or an endpoint that was
        // down while the provider retried — would otherwise mean EVERY delivery
        // silently voids at this deadline despite a courier being on their way.
        // Polling once, here, is what keeps the webhook an optimization rather
        // than a single point of failure for all deliveries.
        if ($assignment !== null && $assignment->external_id !== null) {
            try {
                $assignment = $dispatcher->status($assignment);

                if ($assignment->status->hasCourier()) {
                    Log::info('Courier found at the deadline via polling — no webhook had arrived', [
                        'order_id' => $order->id,
                        'delivery_status' => $assignment->status->value,
                    ]);

                    $settlement->onCourierConfirmed($order);

                    return;
                }
            } catch (Throwable $e) {
                // Unreachable provider is not proof a courier exists. Fall
                // through and fail closed: release the hold.
                Log::warning('Could not read delivery status while expiring an authorization', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Tell the provider to stop looking before we release the money, or
            // a courier could still turn up at a kitchen for a cancelled order.
            try {
                $dispatcher->cancel($assignment);
            } catch (Throwable $e) {
                Log::warning('Could not cancel the delivery while expiring an authorization', [
                    'order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::warning('Authorized delivery expired without a courier', [
            'order_id' => $order->id,
            'restaurant_id' => $order->restaurant_id,
            'deadline_minutes' => self::deadlineMinutes(),
        ]);

        $settlement->onCourierUnavailable(
            $order,
            'no courier was assigned within '.self::deadlineMinutes().' minutes',
        );
    }

    public function failed(?Throwable $exception): void
    {
        // An order left Authorized here is holding a customer's funds with
        // nothing scheduled to release them. That needs a human.
        Log::critical('Failed to expire an authorized delivery — a payment hold may be stranded', [
            'order_id' => $this->orderId,
            'error' => $exception?->getMessage(),
        ]);
    }
}

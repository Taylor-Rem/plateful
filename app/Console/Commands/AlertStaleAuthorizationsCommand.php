<?php

namespace App\Console\Commands;

use App\Enums\PaymentState;
use App\Exceptions\StaleAuthorizationsDetected;
use App\Jobs\ExpireAuthorizedDelivery;
use App\Models\Order;
use Illuminate\Console\Command;

/**
 * The in-code backstop for the one operational dependency nothing else can
 * see: the queue worker. A courier-network delivery holds the customer's card
 * (Authorized) until ExpireAuthorizedDelivery — a queued job — captures or
 * releases it by the courier deadline. With no worker, nothing releases the
 * hold and nobody finds out. Scheduled hourly (routes/console.php); every hit
 * goes through report(), so once a Sentry DSN is set it becomes an alert.
 */
class AlertStaleAuthorizationsCommand extends Command
{
    protected $signature = 'orders:alert-stale-authorizations
        {--older-than= : Minutes an order may stay authorized before it is reported (default: 6x the courier deadline, at least 60)}';

    protected $description = 'Report authorized orders whose card hold should already have been captured or released';

    public function handle(): int
    {
        $minutes = (int) ($this->option('older-than') ?: max(60, ExpireAuthorizedDelivery::deadlineMinutes() * 6));

        $stale = Order::withoutTenantScope()
            ->where('payment_state', PaymentState::Authorized->value)
            ->where('authorized_at', '<', now()->subMinutes($minutes))
            ->orderBy('authorized_at')
            ->get(['id', 'restaurant_id', 'authorized_at']);

        if ($stale->isEmpty()) {
            $this->info("No orders authorized for more than {$minutes} minutes.");

            return self::SUCCESS;
        }

        foreach ($stale as $order) {
            $this->line("  order {$order->id} (restaurant {$order->restaurant_id}) authorized {$order->authorized_at->diffForHumans()}");
        }

        // report() rather than throw: the scheduler should not treat this as a
        // crash, but the exception handler routes it to the log and to Sentry.
        report(new StaleAuthorizationsDetected($stale->count(), $minutes, $stale->pluck('id')->all()));

        $this->error("{$stale->count()} order(s) still authorized after {$minutes} minutes — check the queue worker.");

        return self::FAILURE;
    }
}

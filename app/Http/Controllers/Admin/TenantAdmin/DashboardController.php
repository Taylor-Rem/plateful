<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\DashboardStatsData;
use App\Data\OrderSummaryData;
use App\Data\RestaurantData;
use App\Enums\OrderStatus;
use App\Enums\PaymentState;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Support\Onboarding\OnboardingSteps;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Restaurant $restaurant, OnboardingSteps $steps): Response
    {
        return Inertia::render('Admin/TenantAdmin/Dashboard', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'stats' => $this->stats($restaurant),
            'recentOrders' => Order::query()
                ->where('restaurant_id', $restaurant->id)
                ->orderByDesc('placed_at')
                ->limit(6)
                ->get()
                ->map(fn (Order $order) => OrderSummaryData::fromModel($order))
                ->all(),
            'setup' => $this->setupSurface($restaurant, $steps),
        ]);
    }

    /**
     * One grouped aggregate over today's window plus the pending count.
     * "Today" is the restaurant's local day compared against UTC placed_at.
     * Revenue is money actually taken: captured payments net of refunds —
     * an authorized courier hold counts as an order but not as revenue.
     */
    private function stats(Restaurant $restaurant): DashboardStatsData
    {
        $timezone = $restaurant->timezone ?: 'America/New_York';
        $startOfDay = CarbonImmutable::now($timezone)->startOfDay();

        $today = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereBetween('placed_at', [$startOfDay->utc(), $startOfDay->addDay()->utc()])
            ->where('status', '!=', OrderStatus::Cancelled)
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN payment_state = ? THEN total_cents - COALESCE(refunded_cents, 0) END), 0) as revenue_cents',
                [PaymentState::Captured->value],
            )
            ->selectRaw(
                'COUNT(CASE WHEN payment_state = ? THEN 1 END) as captured_count',
                [PaymentState::Captured->value],
            )
            ->first();

        $revenueCents = (int) $today->revenue_cents;
        $capturedCount = (int) $today->captured_count;

        return new DashboardStatsData(
            ordersToday: (int) $today->orders_count,
            revenueTodayCents: $revenueCents,
            avgTicketCents: $capturedCount > 0 ? intdiv($revenueCents, $capturedCount) : null,
            pendingCount: Order::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('status', OrderStatus::Pending)
                ->count(),
            date: $startOfDay->toDateString(),
            timezone: $timezone,
        );
    }

    /**
     * The "finish setup" surface: null once the restaurant is live, otherwise
     * the same step state the onboarding wizard computes.
     *
     * @return array{canGoLive: bool, remaining: array<int, array<string, mixed>>}|null
     */
    private function setupSurface(Restaurant $restaurant, OnboardingSteps $steps): ?array
    {
        if ($restaurant->isLive()) {
            return null;
        }

        return [
            'canGoLive' => $steps->canGoLive($restaurant),
            'remaining' => $steps->remaining($restaurant),
        ];
    }
}

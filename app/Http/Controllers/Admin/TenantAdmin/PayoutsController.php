<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\Stripe\StripeConnectService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PayoutsController extends Controller
{
    public function __construct(private StripeConnectService $connect) {}

    /**
     * Operational view of the restaurant's Stripe payouts plus the Plateful
     * fees they've paid year-to-date. Read-only; bank changes go through the
     * Express dashboard.
     */
    public function index(Request $request, Restaurant $restaurant): Response
    {
        $payouts = [];
        $hasMore = false;

        if ($restaurant->isStripeReady()) {
            $params = ['limit' => 20];
            if ($after = $request->string('starting_after')->toString()) {
                $params['starting_after'] = $after;
            }

            $collection = $this->connect->listPayouts($restaurant, $params);
            $hasMore = (bool) ($collection->has_more ?? false);

            foreach ($collection->data as $payout) {
                $payouts[] = [
                    'id' => $payout->id,
                    'amountCents' => (int) $payout->amount,
                    'currency' => strtoupper((string) $payout->currency),
                    'status' => $payout->status,
                    'arrivalDate' => $payout->arrival_date
                        ? CarbonImmutable::createFromTimestamp($payout->arrival_date)->toIso8601String()
                        : null,
                    'createdAt' => $payout->created
                        ? CarbonImmutable::createFromTimestamp($payout->created)->toIso8601String()
                        : null,
                ];
            }
        }

        return Inertia::render('Admin/TenantAdmin/Payouts', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'payouts' => $payouts,
            'hasMore' => $hasMore,
            'ytdFeesCents' => $this->ytdPlatefulFeesCents($restaurant),
            'currentYear' => (int) CarbonImmutable::now()->year,
            'stripeConnected' => $restaurant->isStripeReady(),
            'dashboardPath' => "/{$restaurant->subdomain}/onboarding/stripe/dashboard",
        ]);
    }

    /**
     * Sum of application fees Plateful has retained this calendar year: paid
     * orders that weren't refunded.
     */
    private function ytdPlatefulFeesCents(Restaurant $restaurant): int
    {
        return (int) Order::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->whereNull('refunded_at')
            ->where('placed_at', '>=', CarbonImmutable::now()->startOfYear())
            ->sum('application_fee_cents');
    }
}

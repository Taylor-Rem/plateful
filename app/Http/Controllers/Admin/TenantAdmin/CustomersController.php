<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\CustomerData;
use App\Data\CustomerStatsData;
use App\Data\CustomerStatsMonthData;
use App\Data\RestaurantData;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoints;
use App\Models\Order;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The ownership proof (customers page plan, Phase 1): every online-ordering
 * customer of this restaurant, with the counters the pivot already maintains —
 * and a CSV export, because "the customer is yours" has to be demonstrable.
 */
class CustomersController extends Controller
{
    protected const SORTABLE = [
        'name' => 'users.name',
        'total_orders' => 'restaurant_customer.total_orders',
        'total_spent' => 'restaurant_customer.total_spent_cents',
        'first_ordered' => 'restaurant_customer.first_ordered_at',
        'last_ordered' => 'restaurant_customer.last_ordered_at',
    ];

    public function index(Request $request, Restaurant $restaurant): Response
    {
        $filters = $this->normalizeFilters($request);

        $sort = array_key_exists((string) $request->input('sort'), self::SORTABLE)
            ? (string) $request->input('sort')
            : 'last_ordered';
        $dir = $request->input('dir') === 'asc' ? 'asc' : 'desc';

        $query = $this->customersQuery($restaurant, $filters)
            ->orderBy(self::SORTABLE[$sort], $dir)
            ->orderBy('restaurant_customer.id', 'desc');

        $paginator = $query->paginate(25)->withQueryString();

        $customers = collect($paginator->items())
            ->map(fn (RestaurantCustomer $pivot) => CustomerData::fromModel($pivot))
            ->values()
            ->all();

        $base = RestaurantCustomer::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereHas('user');

        return Inertia::render('Admin/TenantAdmin/Customers/Index', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'customers' => $customers,
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'search' => $filters['search'],
                'ordered' => $filters['ordered'],
                'marketing' => $filters['marketing'],
                'sort' => $sort,
                'dir' => $dir,
            ],
            'stats' => [
                'totalCustomers' => (clone $base)->count(),
                'optedInCount' => (clone $base)->emailOptedIn()->count(),
            ],
        ]);
    }

    /**
     * The regulars view (customers page plan, Phase 2): how much of this
     * restaurant's online revenue comes from repeat guests. Online orders
     * only; repeat metrics cover signed-in customers, guest checkout is its
     * own slice so chart totals reconcile with what the owner knows they sold.
     */
    public function stats(Restaurant $restaurant): Response
    {
        $topCustomers = $this->customersQuery($restaurant, ['search' => '', 'ordered' => null, 'marketing' => null])
            ->orderByDesc('restaurant_customer.total_spent_cents')
            ->orderBy('restaurant_customer.id')
            ->limit(10)
            ->get()
            ->map(fn (RestaurantCustomer $pivot) => CustomerData::fromModel($pivot))
            ->all();

        return Inertia::render('Admin/TenantAdmin/Customers/Stats', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'stats' => $this->customerStats($restaurant),
            'topCustomers' => $topCustomers,
        ]);
    }

    /**
     * Everything order-derived is computed in one PHP pass over this
     * restaurant's non-cancelled orders (restaurant order counts are small,
     * and it keeps the month bucketing timezone-correct across DB engines).
     * Only this restaurant's orders are fetched, so a user's history at
     * another restaurant can never make their first order here look like a
     * repeat.
     */
    private function customerStats(Restaurant $restaurant): CustomerStatsData
    {
        $timezone = $restaurant->timezone ?: 'America/New_York';

        $orders = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('status', '!=', OrderStatus::Cancelled)
            ->orderBy('placed_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'placed_at', 'total_cents']);

        $seenUsers = [];
        $placedAtByUser = [];
        $identifiedOrders = 0;
        $identifiedRevenueCents = 0;
        $repeatOrders = 0;
        $repeatRevenueCents = 0;
        $isRepeatOrder = [];

        foreach ($orders as $order) {
            if ($order->user_id === null) {
                continue;
            }

            $identifiedOrders++;
            $identifiedRevenueCents += (int) $order->total_cents;

            $isRepeat = isset($seenUsers[$order->user_id]);
            $seenUsers[$order->user_id] = true;
            $isRepeatOrder[$order->id] = $isRepeat;
            $placedAtByUser[$order->user_id][] = $order->placed_at;

            if ($isRepeat) {
                $repeatOrders++;
                $repeatRevenueCents += (int) $order->total_cents;
            }
        }

        $firstMonth = CarbonImmutable::now($timezone)->startOfMonth()->subMonths(11);
        $buckets = [];

        for ($i = 0; $i < 12; $i++) {
            $buckets[$firstMonth->addMonths($i)->format('Y-m')] = ['new' => 0, 'returning' => 0, 'guest' => 0];
        }

        foreach ($orders as $order) {
            $month = $order->placed_at->copy()->setTimezone($timezone)->format('Y-m');

            if (! isset($buckets[$month])) {
                continue;
            }

            $series = $order->user_id === null
                ? 'guest'
                : ($isRepeatOrder[$order->id] ? 'returning' : 'new');

            $buckets[$month][$series] += (int) $order->total_cents;
        }

        $gapsDays = [];

        foreach ($placedAtByUser as $placedAts) {
            for ($i = 1; $i < count($placedAts); $i++) {
                $gapsDays[] = $placedAts[$i - 1]->diffInSeconds($placedAts[$i]) / 86400;
            }
        }

        $pivotAggregates = RestaurantCustomer::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereHas('user')
            ->selectRaw('COUNT(*) as customers_count')
            ->selectRaw('COUNT(CASE WHEN total_orders >= 1 THEN 1 END) as ordered_customers_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN total_orders >= 1 THEN total_orders END), 0) as ordered_orders_sum')
            ->first();

        $orderedCustomers = (int) $pivotAggregates->ordered_customers_count;

        return new CustomerStatsData(
            repeatOrderPct: $identifiedOrders > 0
                ? round($repeatOrders / $identifiedOrders * 100, 1)
                : null,
            repeatRevenuePct: $identifiedRevenueCents > 0
                ? round($repeatRevenueCents / $identifiedRevenueCents * 100, 1)
                : null,
            avgOrdersPerCustomer: $orderedCustomers > 0
                ? round(((int) $pivotAggregates->ordered_orders_sum) / $orderedCustomers, 1)
                : null,
            medianDaysBetweenOrders: $this->median($gapsDays),
            identifiedCustomers: (int) $pivotAggregates->customers_count,
            identifiedOrders: $identifiedOrders,
            monthly: collect($buckets)
                ->map(fn (array $cents, string $month) => new CustomerStatsMonthData(
                    month: $month,
                    newCents: $cents['new'],
                    returningCents: $cents['returning'],
                    guestCents: $cents['guest'],
                ))
                ->values()
                ->all(),
        );
    }

    /**
     * Median of the gaps, one decimal; null under two pairs (per the Phase 2
     * spec, a single gap is not enough signal for a "typical" cadence).
     *
     * @param  array<int, float>  $gapsDays
     */
    private function median(array $gapsDays): ?float
    {
        if (count($gapsDays) < 2) {
            return null;
        }

        sort($gapsDays);
        $mid = intdiv(count($gapsDays), 2);

        $median = count($gapsDays) % 2 === 1
            ? $gapsDays[$mid]
            : ($gapsDays[$mid - 1] + $gapsDays[$mid]) / 2;

        return round($median, 1);
    }

    /**
     * Stream the current view as CSV. The consent columns make the exported
     * list legally usable in Mailchimp/etc., not just data — that is the
     * portability story the export exists to tell.
     */
    public function export(Request $request, Restaurant $restaurant): StreamedResponse
    {
        $filters = $this->normalizeFilters($request);
        $query = $this->customersQuery($restaurant, $filters);

        $filename = $restaurant->subdomain.'-customers-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Name',
                'Email',
                'Phone',
                'Total orders',
                'Lifetime spend',
                'First order',
                'Last order',
                'Loyalty points',
                'Marketing opt-in',
                'Marketing opted in at',
            ]);

            $query->chunkById(500, function ($rows) use ($out): void {
                foreach ($rows as $pivot) {
                    fputcsv($out, [
                        $pivot->user->name,
                        $pivot->user->email,
                        $pivot->user->phone,
                        (int) $pivot->total_orders,
                        number_format(((int) $pivot->total_spent_cents) / 100, 2, '.', ''),
                        $pivot->first_ordered_at?->toIso8601String(),
                        $pivot->last_ordered_at?->toIso8601String(),
                        (int) ($pivot->loyalty_points_balance ?? 0),
                        $pivot->isEmailOptedIn() ? 'yes' : 'no',
                        $pivot->isEmailOptedIn() ? $pivot->marketing_email_opted_in_at?->toIso8601String() : null,
                    ]);
                }
            }, 'restaurant_customer.id', 'id');

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{search: string, ordered: ?int, marketing: ?string}
     */
    protected function normalizeFilters(Request $request): array
    {
        $ordered = in_array($request->input('ordered'), ['30', '90', 30, 90], true)
            ? (int) $request->input('ordered')
            : null;

        return [
            'search' => trim((string) $request->input('search', '')),
            'ordered' => $ordered,
            'marketing' => $request->input('marketing') === 'opted_in' ? 'opted_in' : null,
        ];
    }

    /**
     * Soft-deleted users are excluded outright: a deleted account's contact
     * info must not appear on the page or in the export.
     *
     * @param  array{search: string, ordered: ?int, marketing: ?string}  $filters
     */
    protected function customersQuery(Restaurant $restaurant, array $filters): Builder
    {
        $query = RestaurantCustomer::query()
            ->join('users', 'users.id', '=', 'restaurant_customer.user_id')
            ->whereNull('users.deleted_at')
            ->where('restaurant_customer.restaurant_id', $restaurant->id)
            ->select('restaurant_customer.*')
            ->addSelect([
                'loyalty_points_balance' => LoyaltyPoints::withoutTenantScope()
                    ->select('points')
                    ->whereColumn('loyalty_points.user_id', 'restaurant_customer.user_id')
                    ->where('loyalty_points.restaurant_id', $restaurant->id),
            ])
            ->with('user');

        if ($filters['search'] !== '') {
            $query->where(function (Builder $q) use ($filters): void {
                $q->where('users.name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('users.email', 'like', '%'.$filters['search'].'%');
            });
        }

        if ($filters['ordered'] !== null) {
            $query->where('restaurant_customer.last_ordered_at', '>=', now()->subDays($filters['ordered']));
        }

        if ($filters['marketing'] === 'opted_in') {
            $query->emailOptedIn();
        }

        return $query;
    }
}

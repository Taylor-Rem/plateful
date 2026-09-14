<?php

namespace App\Support\Platform;

use App\Data\EarnerData;
use App\Data\EarningsSummaryData;
use App\Data\FeeDistributionData;
use App\Data\RestaurantEarningsData;
use App\Enums\RevenueRole;
use App\Models\FeeDistribution;
use App\Models\Order;
use App\Models\PlatformRoleHolder;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\MonthlyCommissionCap;
use App\Services\RevenueSplitResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform earnings reads, shared by the super-admin Earnings page, the
 * operator API and the MCP tools. Everything reads the fee_distributions
 * ledger and the orders' own commission columns; refunded orders are
 * excluded throughout because their fee was reversed (nobody earned it).
 */
class EarningsQuery
{
    public function __construct(
        protected RevenueSplitResolver $splits,
        protected MonthlyCommissionCap $cap,
    ) {}

    /**
     * Parse `YYYY-MM` into the first day of that month (app timezone);
     * anything else is the current month.
     */
    public function month(?string $month): CarbonImmutable
    {
        if ($month !== null && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');

            if ($parsed !== false) {
                return $parsed->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /**
     * How much each person earned from the retained fee in a month, by role:
     * the payout sheet.
     */
    public function payoutSummary(CarbonImmutable $month): EarningsSummaryData
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();

        $aggregates = FeeDistribution::query()
            ->whereBetween('earned_at', [$start, $end])
            ->whereHas('order', fn ($q) => $q->whereNull('refunded_at'))
            ->selectRaw('user_id, role, SUM(amount_cents) as amount_cents')
            ->groupBy('user_id', 'role')
            ->get();

        $names = User::query()
            ->whereIn('id', $aggregates->pluck('user_id')->filter()->unique())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        /** @var array<int, array{userId: ?int, name: string, email: ?string, roles: array<string, int>, totalCents: int}> $earners */
        $earners = [];

        foreach ($aggregates as $row) {
            $key = $row->user_id ?? 0;

            if (! isset($earners[$key])) {
                $user = $row->user_id ? $names->get($row->user_id) : null;
                $earners[$key] = [
                    'userId' => $row->user_id,
                    'name' => $user->name ?? 'Removed user',
                    'email' => $user->email ?? null,
                    'roles' => [],
                    'totalCents' => 0,
                ];
            }

            $earners[$key]['roles'][$row->role->value] = (int) $row->amount_cents;
            $earners[$key]['totalCents'] += (int) $row->amount_cents;
        }

        usort($earners, fn ($a, $b) => $b['totalCents'] <=> $a['totalCents']);

        $holder = fn (RevenueRole $role): ?array => ($u = PlatformRoleHolder::holder($role))
            ? ['id' => $u->id, 'name' => $u->name]
            : null;

        return new EarningsSummaryData(
            month: $start->format('Y-m'),
            monthLabel: $start->format('F Y'),
            totalCents: array_sum(array_column($earners, 'totalCents')),
            shares: $this->splits->shares(),
            founder: $holder(RevenueRole::Founder),
            operator: $holder(RevenueRole::Operator),
            earners: array_map(fn (array $e) => new EarnerData(
                userId: $e['userId'],
                name: $e['name'],
                email: $e['email'],
                roles: $e['roles'],
                totalCents: $e['totalCents'],
            ), $earners),
        );
    }

    /**
     * What each restaurant generated in a month: food sold, fee retained,
     * commission against its cap, delivery margin. Every restaurant appears,
     * ordered by commission, so a quiet one is visible as a zero row.
     *
     * @return array<int, RestaurantEarningsData>
     */
    public function restaurantBreakdown(CarbonImmutable $month): array
    {
        $start = $month->startOfMonth();
        $end = $month->endOfMonth();
        $isCurrentMonth = $start->isSameMonth(CarbonImmutable::now());

        $orders = Order::withoutTenantScope()
            ->whereBetween('placed_at', [$start, $end])
            ->selectRaw('restaurant_id')
            ->selectRaw('COUNT(*) as orders_count')
            ->selectRaw('SUM(CASE WHEN refunded_at IS NOT NULL THEN 1 ELSE 0 END) as refunded_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN refunded_at IS NULL THEN subtotal_cents ELSE 0 END), 0) as food_cents')
            ->selectRaw('COALESCE(SUM(CASE WHEN refunded_at IS NULL THEN application_fee_cents ELSE 0 END), 0) as fee_cents')
            ->selectRaw('COALESCE(SUM(CASE WHEN refunded_at IS NULL THEN platform_commission_cents ELSE 0 END), 0) as commission_cents')
            ->selectRaw('COALESCE(SUM(CASE WHEN refunded_at IS NULL THEN delivery_margin_cents ELSE 0 END), 0) as margin_cents')
            ->groupBy('restaurant_id')
            ->get()
            ->keyBy('restaurant_id');

        $ledger = FeeDistribution::query()
            ->whereBetween('earned_at', [$start, $end])
            ->whereHas('order', fn ($q) => $q->whereNull('refunded_at'))
            ->selectRaw('restaurant_id, SUM(amount_cents) as amount_cents')
            ->groupBy('restaurant_id')
            ->pluck('amount_cents', 'restaurant_id');

        return Restaurant::query()
            ->orderBy('name')
            ->get()
            ->map(function (Restaurant $restaurant) use ($orders, $ledger, $start, $isCurrentMonth): RestaurantEarningsData {
                $row = $orders->get($restaurant->id);
                $commission = (int) ($row->commission_cents ?? 0);
                $cap = $this->cap->capFor($restaurant);

                return new RestaurantEarningsData(
                    restaurantId: $restaurant->id,
                    name: $restaurant->name,
                    subdomain: $restaurant->subdomain,
                    status: $restaurant->status->value,
                    month: $start->format('Y-m'),
                    orders: (int) ($row->orders_count ?? 0),
                    refundedOrders: (int) ($row->refunded_count ?? 0),
                    foodSubtotalCents: (int) ($row->food_cents ?? 0),
                    applicationFeeCents: (int) ($row->fee_cents ?? 0),
                    commissionCents: $commission,
                    deliveryMarginCents: (int) ($row->margin_cents ?? 0),
                    ledgerCents: (int) ($ledger[$restaurant->id] ?? 0),
                    feePercent: (float) $restaurant->application_fee_percent,
                    capCents: $cap,
                    capReached: $commission >= $cap,
                    capRemainingCents: $isCurrentMonth ? $this->cap->remainingFor($restaurant) : null,
                );
            })
            ->sortByDesc(fn (RestaurantEarningsData $r) => [$r->commissionCents, $r->name])
            ->values()
            ->all();
    }

    /**
     * The raw ledger rows behind a payout figure, newest first.
     *
     * @param  array{restaurant?: ?Restaurant, user?: ?User, order?: ?string, role?: ?RevenueRole, from?: ?CarbonImmutable, to?: ?CarbonImmutable, include_refunded?: bool}  $filters
     * @return LengthAwarePaginator<int, FeeDistribution>
     */
    public function ledger(array $filters, int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        // History must survive deletions: a removed restaurant or earner is
        // still named on the row it earned.
        $query = FeeDistribution::query()
            ->with([
                'order:id,number,refunded_at',
                'restaurant' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'subdomain']),
                'user' => fn ($q) => $q->withTrashed()->select(['id', 'name', 'email']),
            ])
            ->when($filters['restaurant'] ?? null, fn (Builder $q, Restaurant $r) => $q->where('restaurant_id', $r->id))
            ->when($filters['user'] ?? null, fn (Builder $q, User $u) => $q->where('user_id', $u->id))
            ->when($filters['order'] ?? null, fn (Builder $q, string $number) => $q->whereHas('order', fn ($o) => $o->where('number', $number)))
            ->when($filters['role'] ?? null, fn (Builder $q, RevenueRole $role) => $q->where('role', $role->value))
            ->when($filters['from'] ?? null, fn (Builder $q, CarbonImmutable $from) => $q->where('earned_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $q, CarbonImmutable $to) => $q->where('earned_at', '<=', $to))
            ->when(! ($filters['include_refunded'] ?? false), fn (Builder $q) => $q->whereHas('order', fn ($o) => $o->whereNull('refunded_at')))
            ->orderByDesc('earned_at')
            ->orderByDesc('id');

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  LengthAwarePaginator<int, FeeDistribution>  $paginator
     * @return array<int, FeeDistributionData>
     */
    public function ledgerData(LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())
            ->map(fn (FeeDistribution $row) => FeeDistributionData::fromModel($row))
            ->values()
            ->all();
    }
}

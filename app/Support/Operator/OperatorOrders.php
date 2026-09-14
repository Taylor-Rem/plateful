<?php

namespace App\Support\Operator;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Order reads shared by the operator REST endpoints and the MCP tools: the
 * same filters as the tenant admin Orders page, the kitchen board, and a
 * lookup by id or number that never leaves the restaurant.
 */
class OperatorOrders
{
    /**
     * Statuses on the kitchen board, in display order. Pending is on the
     * board because an order only exists once it is PAID.
     */
    public const BOARD_STATUSES = [
        OrderStatus::Pending,
        OrderStatus::Confirmed,
        OrderStatus::Preparing,
        OrderStatus::Ready,
    ];

    /**
     * @param  array{status?: array<int, string>, search?: string, from?: ?string, to?: ?string, since?: ?string}  $filters
     * @return LengthAwarePaginator<int, Order>
     */
    public function paginate(Restaurant $restaurant, array $filters, int $perPage = 25, int $page = 1): LengthAwarePaginator
    {
        return $this->query($restaurant, $filters)
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * @param  array{status?: array<int, string>, search?: string, from?: ?string, to?: ?string, since?: ?string}  $filters
     * @return Builder<Order>
     */
    public function query(Restaurant $restaurant, array $filters): Builder
    {
        $query = Order::query()->where('restaurant_id', $restaurant->id);

        $statuses = $this->normalizeStatuses($filters['status'] ?? []);

        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $q->where('number', 'like', '%'.$search.'%')
                    ->orWhere('customer_name', 'like', '%'.$search.'%')
                    ->orWhere('customer_email', 'like', '%'.$search.'%');
            });
        }

        if (! empty($filters['from'])) {
            $query->where('placed_at', '>=', CarbonImmutable::parse($filters['from'], $restaurant->timezone)->utc());
        }

        if (! empty($filters['to'])) {
            $query->where('placed_at', '<=', CarbonImmutable::parse($filters['to'], $restaurant->timezone)->utc());
        }

        // Poll cursor: anything touched after this instant.
        if (! empty($filters['since'])) {
            $query->where('updated_at', '>', CarbonImmutable::parse($filters['since']));
        }

        return $query;
    }

    /**
     * Every order currently on the kitchen board, oldest first, items
     * loaded.
     *
     * @return Collection<int, Order>
     */
    public function board(Restaurant $restaurant, ?string $since = null): Collection
    {
        return Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereIn('status', array_map(fn (OrderStatus $s) => $s->value, self::BOARD_STATUSES))
            ->when($since, fn (Builder $q) => $q->where('updated_at', '>', CarbonImmutable::parse($since)))
            ->with(['items', 'deliveryAssignment'])
            ->orderBy('placed_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Find an order inside the restaurant by numeric id or by its number
     * (`ABC-12345`), or null.
     */
    public function find(Restaurant $restaurant, string|int $idOrNumber): ?Order
    {
        $query = Order::query()->where('restaurant_id', $restaurant->id);

        $order = is_numeric($idOrNumber)
            ? $query->whereKey((int) $idOrNumber)->first()
            : null;

        return $order ?? Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('number', (string) $idOrNumber)
            ->first();
    }

    /**
     * Status counts for the restaurant, every status present.
     *
     * @return array<string, int>
     */
    public function statusCounts(Restaurant $restaurant): array
    {
        $raw = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];

        foreach (OrderStatus::cases() as $case) {
            $counts[$case->value] = (int) ($raw[$case->value] ?? 0);
        }

        return $counts;
    }

    /**
     * @return array<int, string>
     */
    public function normalizeStatuses(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        $values = is_array($input) ? $input : explode(',', (string) $input);
        $valid = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        return array_values(array_intersect($valid, array_map(fn ($v) => trim((string) $v), $values)));
    }
}

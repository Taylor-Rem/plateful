<?php

namespace App\Support\Customers;

use App\Models\LoyaltyPoints;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use Illuminate\Database\Eloquent\Builder;

/**
 * The customers-page query, shared by the tenant admin page, its CSV export
 * and the operator API. Soft-deleted users are excluded outright: a deleted
 * account's contact info must not appear anywhere.
 */
class CustomersQuery
{
    public const SORTABLE = [
        'name' => 'users.name',
        'total_orders' => 'restaurant_customer.total_orders',
        'total_spent' => 'restaurant_customer.total_spent_cents',
        'first_ordered' => 'restaurant_customer.first_ordered_at',
        'last_ordered' => 'restaurant_customer.last_ordered_at',
    ];

    /**
     * @param  array{search: string, ordered: ?int, marketing: ?string}  $filters
     * @return Builder<RestaurantCustomer>
     */
    public function build(Restaurant $restaurant, array $filters): Builder
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

    /**
     * Sort key + direction from raw input, defaulting to most recent order.
     *
     * @return array{0: string, 1: string}
     */
    public function sort(mixed $sort, mixed $dir): array
    {
        $sort = array_key_exists((string) $sort, self::SORTABLE) ? (string) $sort : 'last_ordered';

        return [$sort, $dir === 'asc' ? 'asc' : 'desc'];
    }
}

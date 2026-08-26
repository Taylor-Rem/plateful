<?php

namespace App\Services\Campaigns;

use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Resolves a campaign's audience_filter to the concrete consent-eligible
 * customers of a restaurant. Eligibility is strict opt-in (campaigns plan,
 * locked): pivot opted in and not opted out, user not soft-deleted, and the
 * address not on the platform-wide suppression list. Query shape mirrors
 * CustomersController::customersQuery().
 */
class CampaignAudience
{
    /**
     * @param  array{type?: string, days?: int, min_orders?: int}  $filter
     * @return Builder<RestaurantCustomer> pivot rows (user eager-loaded)
     */
    public function query(Restaurant $restaurant, array $filter): Builder
    {
        $query = RestaurantCustomer::query()
            ->join('users', 'users.id', '=', 'restaurant_customer.user_id')
            ->whereNull('users.deleted_at')
            ->where('restaurant_customer.restaurant_id', $restaurant->id)
            ->emailOptedIn()
            ->whereNotExists(function (QueryBuilder $q): void {
                $q->selectRaw('1')
                    ->from('suppressed_emails')
                    ->whereColumn('suppressed_emails.email', 'users.email');
            })
            ->select('restaurant_customer.*')
            ->with('user');

        match ($filter['type'] ?? 'all') {
            'lapsed' => $query->where(
                'restaurant_customer.last_ordered_at',
                '<=',
                now()->subDays((int) ($filter['days'] ?? 30)),
            ),
            'regulars' => $query->where(
                'restaurant_customer.total_orders',
                '>=',
                (int) ($filter['min_orders'] ?? 3),
            ),
            default => $query,
        };

        return $query;
    }

    /**
     * Live recipient count for the compose UI's audience picker.
     *
     * @param  array{type?: string, days?: int, min_orders?: int}  $filter
     */
    public function count(Restaurant $restaurant, array $filter): int
    {
        return $this->query($restaurant, $filter)->count();
    }
}

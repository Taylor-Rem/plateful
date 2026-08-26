<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\CustomerData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\LoyaltyPoints;
use App\Models\Restaurant;
use App\Models\RestaurantCustomer;
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

<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\OrderData;
use App\Data\OrderEventData;
use App\Data\RestaurantData;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderTransitionRequest;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\OrderTransition;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrdersController extends Controller
{
    public function index(Request $request, Restaurant $restaurant): Response
    {
        $statusFilter = $this->normalizeStatuses($request->input('status'));
        $search = trim((string) $request->input('search', ''));

        $query = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->orderByDesc('placed_at')
            ->orderByDesc('created_at');

        if ($statusFilter !== []) {
            $query->whereIn('status', $statusFilter);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('number', 'like', '%'.$search.'%')
                    ->orWhere('customer_name', 'like', '%'.$search.'%');
            });
        }

        $paginator = $query->paginate(15)->withQueryString();

        $orders = collect($paginator->items())
            ->map(fn (Order $o) => OrderData::fromModel($o))
            ->values()
            ->all();

        $rawCounts = Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $statusCounts = [];
        foreach (OrderStatus::cases() as $case) {
            $statusCounts[$case->value] = (int) ($rawCounts[$case->value] ?? 0);
        }

        return Inertia::render('Admin/TenantAdmin/Orders/Index', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'orders' => $orders,
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'status' => $statusFilter,
                'search' => $search,
            ],
            'statusCounts' => $statusCounts,
        ]);
    }

    public function show(Restaurant $restaurant, Order $order): Response
    {
        if ($order->restaurant_id !== $restaurant->id) {
            throw new NotFoundHttpException;
        }

        $order->load(['items', 'events.user']);

        $events = $order->events
            ->sortByDesc('occurred_at')
            ->values()
            ->map(fn ($e) => OrderEventData::fromModel($e))
            ->all();

        return Inertia::render('Admin/TenantAdmin/Orders/Show', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'order' => OrderData::fromModel($order),
            'events' => $events,
        ]);
    }

    public function transition(
        OrderTransitionRequest $request,
        Restaurant $restaurant,
        Order $order,
        OrderTransition $service,
    ): RedirectResponse {
        if ($order->restaurant_id !== $restaurant->id) {
            throw new NotFoundHttpException;
        }

        $toStatus = OrderStatus::from((string) $request->validated('to_status'));

        $service->apply(
            $order,
            $toStatus,
            $request->user(),
            $request->validated('note'),
        );

        return back()->with('success', 'Order status updated.');
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeStatuses(mixed $input): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        $values = is_array($input) ? $input : [$input];
        $valid = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        return array_values(array_intersect($valid, array_map('strval', $values)));
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Data\OperatorOrderData;
use App\Data\OrderData;
use App\Data\OrderSummaryData;
use App\Data\PaginationMetaData;
use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\OrderTransitionRequest;
use App\Http\Requests\Api\V1\Operator\OrderListRequest;
use App\Models\Order;
use App\Models\Restaurant;
use App\Services\OrderTransition;
use App\Support\Api\ApiActor;
use App\Support\Operator\OperatorOrders;
use Illuminate\Http\JsonResponse;

/**
 * The tenant admin Orders page and kitchen board as JSON. Every route is
 * inside `operator.restaurant`, so the tenant is set and `{order}` binds
 * only within it.
 */
class OrdersController extends Controller
{
    public function __construct(protected OperatorOrders $orders) {}

    public function index(OrderListRequest $request, Restaurant $restaurant): JsonResponse
    {
        $paginator = $this->orders->paginate($restaurant, $request->filters(), $request->perPage(), $request->page());

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Order $o) => OrderSummaryData::fromModel($o))
                ->all(),
            'meta' => [
                ...PaginationMetaData::fromPaginator($paginator)->toArray(),
                'statusCounts' => $this->orders->statusCounts($restaurant),
            ],
        ]);
    }

    public function show(Restaurant $restaurant, Order $order): JsonResponse
    {
        return response()->json(['data' => OperatorOrderData::fromModel($order)]);
    }

    /**
     * Same validation as the web transition; an illegal move is a 422 from
     * InvalidOrderTransitionException.
     */
    public function transition(
        OrderTransitionRequest $request,
        Restaurant $restaurant,
        Order $order,
        OrderTransition $service,
    ): JsonResponse {
        $order = $service->apply(
            $order,
            OrderStatus::from((string) $request->validated('to_status')),
            ApiActor::fromRequest($request)->auditUser(),
            $request->validated('note'),
        );

        return response()->json(['data' => OperatorOrderData::fromModel($order->refresh())]);
    }

    public function kitchen(OrderListRequest $request, Restaurant $restaurant): JsonResponse
    {
        return response()->json([
            'data' => $this->orders->board($restaurant, $request->input('since'))
                ->map(fn (Order $o) => OrderData::fromModel($o))
                ->all(),
            'meta' => ['asOf' => now()->toIso8601String()],
        ]);
    }
}

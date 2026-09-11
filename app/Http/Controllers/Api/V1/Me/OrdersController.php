<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Data\OrderData;
use App\Data\OrderHistoryItemData;
use App\Data\PaginationMetaData;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in customer's orders across every restaurant. Order numbers
 * are globally unique, so detail needs no restaurant in the path.
 */
class OrdersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $paginator = Order::withoutTenantScope()
            ->where('user_id', $request->user()->id)
            ->with(['restaurant', 'items', 'deliveryAssignment'])
            ->orderByDesc('placed_at')
            ->orderByDesc('id')
            ->paginate((int) config('platform.marketplace.per_page', 20));

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Order $order) => OrderHistoryItemData::fromModel($order))
                ->all(),
            'meta' => PaginationMetaData::fromPaginator($paginator),
        ]);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        $order = Order::withoutTenantScope()
            ->where('user_id', $request->user()->id)
            ->where('number', $number)
            ->with(['items', 'deliveryAssignment'])
            ->first();

        abort_if($order === null, 404);

        return response()->json(['data' => OrderData::fromModel($order)]);
    }
}

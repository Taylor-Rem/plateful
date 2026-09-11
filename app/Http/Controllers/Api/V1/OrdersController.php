<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\OrderData;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class OrdersController extends Controller
{
    public const TOKEN_HEADER = 'X-Order-Token';

    /**
     * Poll-friendly order detail: status, and the courier assignment with
     * its tracking URL once dispatched. Access follows OrderPolicy — the
     * signed-in owner, or a guest presenting the confirmation token.
     */
    public function show(Request $request, Restaurant $restaurant, string $number): JsonResponse
    {
        $order = Order::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('number', $number)
            ->with(['items', 'deliveryAssignment'])
            ->first();

        $token = $request->header(self::TOKEN_HEADER);

        abort_if(
            $order === null || ! Gate::forUser($request->user())->allows('view', [$order, is_string($token) ? $token : null]),
            404,
        );

        return response()->json(['data' => OrderData::fromModel($order)]);
    }
}

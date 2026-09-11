<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\CartData;
use App\Data\OrderData;
use App\Data\ReorderResultData;
use App\Exceptions\InvalidCartSelectionException;
use App\Http\Controllers\Controller;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;
use App\Services\CartManager;
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

    /**
     * One-tap reorder: rebuild a cart from a past order at today's menu and
     * prices. Lines whose item is gone or unavailable, or whose options no
     * longer validate, are reported in `skipped` rather than dropped silently.
     * Same access rule as show (owner, or guest with the confirmation token).
     */
    public function reorder(Request $request, Restaurant $restaurant, string $number, CartManager $manager): JsonResponse
    {
        $order = Order::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('number', $number)
            ->with('items')
            ->first();

        $token = $request->header(self::TOKEN_HEADER);

        abort_if(
            $order === null || ! Gate::forUser($request->user())->allows('view', [$order, is_string($token) ? $token : null]),
            404,
        );

        $skipped = [];
        $cart = null;

        foreach ($order->items as $line) {
            $item = $line->menu_item_id
                ? MenuItem::query()->where('restaurant_id', $restaurant->id)->find($line->menu_item_id)
                : null;

            if ($item === null || ! $item->is_available) {
                $skipped[] = ['name' => (string) $line->name, 'reason' => 'No longer on the menu.'];

                continue;
            }

            try {
                $added = $manager->addItem($item, (int) $line->quantity, $this->optionIdsFrom($line), $line->notes);
            } catch (InvalidCartSelectionException) {
                $skipped[] = ['name' => (string) $line->name, 'reason' => 'Its options have changed — add it again from the menu.'];

                continue;
            }

            if ($cart === null) {
                $cart = $added->cart()->first();
                $manager->pin($cart);
            }
        }

        $cart ??= $manager->current();
        $cart?->unsetRelation('items');

        return response()->json([
            'data' => new ReorderResultData(
                cart: $cart !== null ? CartData::fromModel($cart) : null,
                cartToken: $cart?->user_id === null ? $cart?->token : null,
                skipped: $skipped,
            ),
        ], 201);
    }

    /**
     * The option ids a past line was configured with, from its modifiers
     * snapshot (v1 and v2 both carry `selections[].option_id`).
     *
     * @return array<int, int>
     */
    protected function optionIdsFrom(OrderItem $line): array
    {
        $groups = $line->modifiers['groups'] ?? [];

        return collect(is_array($groups) ? $groups : [])
            ->flatMap(fn ($group) => collect($group['selections'] ?? [])->pluck('option_id'))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}

<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\CartData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\AddCartItemRequest;
use App\Http\Requests\Storefront\ReplaceCartItemRequest;
use App\Http\Requests\Storefront\UpdateCartItemRequest;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Services\CartManager;
use Illuminate\Http\JsonResponse;

/**
 * The storefront cart over JSON. Same CartManager, same request rules; the
 * only difference is identity: the app sends X-Cart-Token (handed out in
 * every response here) instead of carrying a cookie. Signed-in customers
 * are matched by user first, as on the web. `$restaurant` is the route's
 * tenant (already applied by `tenant.route`); it is declared so Laravel
 * injects the bound models positionally in route order.
 */
class CartController extends Controller
{
    public function show(Restaurant $restaurant, CartManager $manager): JsonResponse
    {
        return $this->cartResponse($manager->current());
    }

    public function addItem(AddCartItemRequest $request, Restaurant $restaurant, MenuItem $menuItem, CartManager $manager): JsonResponse
    {
        // A first add creates the cart; the request carried no token yet, so
        // read the cart off the line rather than back through the request.
        $line = $manager->addItem($menuItem, $request->quantity(), $request->optionIds(), $request->notes());

        return $this->cartResponse($line->cart()->first(), 201);
    }

    public function updateItem(UpdateCartItemRequest $request, Restaurant $restaurant, CartItem $cartItem, CartManager $manager): JsonResponse
    {
        $this->ensureBelongsToCurrentCart($cartItem, $manager);

        $manager->updateQuantity($cartItem, (int) $request->integer('quantity'));

        return $this->cartResponse($manager->current());
    }

    public function replaceItem(ReplaceCartItemRequest $request, Restaurant $restaurant, CartItem $cartItem, CartManager $manager): JsonResponse
    {
        $this->ensureBelongsToCurrentCart($cartItem, $manager);

        $manager->replaceItem($cartItem, $request->quantity(), $request->optionIds(), $request->notes());

        return $this->cartResponse($manager->current());
    }

    public function removeItem(Restaurant $restaurant, CartItem $cartItem, CartManager $manager): JsonResponse
    {
        $this->ensureBelongsToCurrentCart($cartItem, $manager);

        $manager->removeItem($cartItem);

        return $this->cartResponse($manager->current());
    }

    public function clear(Restaurant $restaurant, CartManager $manager): JsonResponse
    {
        $manager->clear();

        return $this->cartResponse($manager->current());
    }

    /**
     * `data` is null until the first add; `cartToken` is what the app must
     * send back as X-Cart-Token (null for a signed-in customer's user-bound
     * cart, which needs no token).
     */
    protected function cartResponse(?Cart $cart, int $status = 200): JsonResponse
    {
        if ($cart !== null) {
            $cart->unsetRelation('items');
        }

        return response()->json([
            'data' => $cart !== null ? CartData::fromModel($cart) : null,
            'cartToken' => $cart?->user_id === null ? $cart?->token : null,
        ], $status);
    }

    protected function ensureBelongsToCurrentCart(CartItem $item, CartManager $manager): void
    {
        $cart = $manager->current();

        if (! $cart || $item->cart_id !== $cart->id) {
            abort(404);
        }
    }
}

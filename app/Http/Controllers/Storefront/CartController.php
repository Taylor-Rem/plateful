<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\AddCartItemRequest;
use App\Http\Requests\Storefront\UpdateCartItemRequest;
use App\Models\CartItem;
use App\Models\MenuItem;
use App\Services\CartManager;
use Illuminate\Http\RedirectResponse;

class CartController extends Controller
{
    public function addItem(
        AddCartItemRequest $request,
        MenuItem $menuItem,
        CartManager $manager,
    ): RedirectResponse {
        $manager->addItem(
            $menuItem,
            $request->quantity(),
            $request->optionIds(),
        );

        return back(303)->with('success', 'Added to cart.');
    }

    public function updateItem(
        UpdateCartItemRequest $request,
        CartItem $cartItem,
        CartManager $manager,
    ): RedirectResponse {
        $this->ensureBelongsToCurrentCart($cartItem, $manager);

        $manager->updateQuantity($cartItem, (int) $request->integer('quantity'));

        return back(303);
    }

    public function removeItem(CartItem $cartItem, CartManager $manager): RedirectResponse
    {
        $this->ensureBelongsToCurrentCart($cartItem, $manager);

        $manager->removeItem($cartItem);

        return back(303);
    }

    public function clear(CartManager $manager): RedirectResponse
    {
        $manager->clear();

        return back(303);
    }

    protected function ensureBelongsToCurrentCart(CartItem $item, CartManager $manager): void
    {
        $cart = $manager->current();

        if (! $cart || $item->cart_id !== $cart->id) {
            abort(404);
        }
    }
}

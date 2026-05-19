<?php

namespace App\Http\Controllers\Storefront;

use App\Data\AddressData;
use App\Data\CartData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\CheckoutRequest;
use App\Services\CartManager;
use App\Services\OrderPlacement;
use App\Support\BrandColors;
use App\Tenancy\CurrentTenant;
use Illuminate\Cookie\CookieJar;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CheckoutController extends Controller
{
    public const RECENT_ORDER_COOKIE = 'plateful_recent_order';

    public function show(CurrentTenant $tenant, CartManager $manager): Response|RedirectResponse
    {
        $cart = $manager->current();

        if (! $cart || $cart->items()->count() === 0) {
            return redirect()->route('storefront.home')
                ->with('error', 'Your cart is empty.');
        }

        $restaurant = $tenant->get();
        $user = request()->user();

        $savedAddresses = [];
        if ($user) {
            $savedAddresses = $user->addresses()->orderByDesc('is_default')->orderByDesc('id')->get()
                ->map(fn ($a) => AddressData::fromModel($a))
                ->all();
        }

        return Inertia::render('Storefront/Checkout', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'cart' => CartData::fromModel($cart),
            'savedAddresses' => $savedAddresses,
            'tipPresets' => [0, 15, 18, 20],
            'brand' => BrandColors::paletteFor(
                $restaurant->primary_color,
                $restaurant->secondary_color,
            ),
        ]);
    }

    public function store(
        CheckoutRequest $request,
        CurrentTenant $tenant,
        CartManager $manager,
        OrderPlacement $placement,
        CookieJar $cookies,
    ): RedirectResponse {
        $cart = $manager->current();
        $restaurant = $tenant->get();

        $order = $placement->place(
            $cart,
            $restaurant,
            $request->validated(),
            $request->user(),
        );

        $cookies->queue($cookies->make(
            name: self::RECENT_ORDER_COOKIE,
            value: $order->confirmation_token,
            minutes: 60,
            path: '/',
            domain: null,
            secure: app()->environment('production'),
            httpOnly: true,
            raw: false,
            sameSite: 'lax',
        ));

        return redirect()
            ->route('storefront.orders.show', ['number' => $order->number])
            ->with('success', 'Order placed!');
    }
}

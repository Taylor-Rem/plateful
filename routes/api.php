<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SocialLoginController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\Me\AddressesController;
use App\Http\Controllers\Api\V1\Me\DevicesController;
use App\Http\Controllers\Api\V1\Me\FavoritesController;
use App\Http\Controllers\Api\V1\Me\OrdersController as MyOrdersController;
use App\Http\Controllers\Api\V1\Me\WalletController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Operator\ApiKeysController as OperatorApiKeysController;
use App\Http\Controllers\Api\V1\Operator\CustomersController as OperatorCustomersController;
use App\Http\Controllers\Api\V1\Operator\MeController as OperatorMeController;
use App\Http\Controllers\Api\V1\Operator\MenuController as OperatorMenuController;
use App\Http\Controllers\Api\V1\Operator\OrdersController as OperatorOrdersController;
use App\Http\Controllers\Api\V1\Operator\RestaurantsController as OperatorRestaurantsController;
use App\Http\Controllers\Api\V1\OrdersController;
use App\Http\Controllers\Api\V1\RestaurantMembershipController;
use App\Http\Controllers\Api\V1\RestaurantsController;
use App\Http\Controllers\Storefront\AddressLookupController;
use App\Http\Controllers\Storefront\DeliveryQuoteController;
use Illuminate\Support\Facades\Route;

/*
 * Public API for the Plateful mobile app â stateless JSON with Sanctum bearer
 * tokens, mounted on the primary host at /api/v1. Response DTOs in app/Data
 * (and their generated TypeScript) are the contract the app repo consumes:
 * additive-only changes here, anything else goes to /v2.
 * Plan: docs/plateful_app_plan.md.
 */
Route::domain(config('platform.primary_domain'))
    ->prefix('api/v1')
    ->name('api.v1.')
    ->group(function () {
        Route::prefix('auth')->name('auth.')->group(function () {
            Route::post('login', [LoginController::class, 'store'])
                ->middleware('throttle:login')
                ->name('login');
            Route::post('register', [RegisterController::class, 'store'])
                ->middleware('throttle:api-auth')
                ->name('register');
            Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
                ->middleware('throttle:api-auth')
                ->name('forgot-password');
            Route::post('google', [SocialLoginController::class, 'google'])
                ->middleware('throttle:api-auth')
                ->name('google');
            Route::post('apple', [SocialLoginController::class, 'apple'])
                ->middleware('throttle:api-auth')
                ->name('apple');
            Route::post('two-factor', [TwoFactorChallengeController::class, 'store'])
                ->middleware(['auth:sanctum', 'ability:two-factor-challenge', 'throttle:api-two-factor'])
                ->name('two-factor');
            Route::delete('logout', [LoginController::class, 'destroy'])
                ->middleware('auth:sanctum')
                ->name('logout');
        });

        Route::middleware(['auth:sanctum', 'abilities:customer'])->group(function () {
            Route::get('me', [MeController::class, 'show'])->name('me.show');
            Route::patch('me', [MeController::class, 'update'])->name('me.update');
            Route::delete('me', [MeController::class, 'destroy'])->name('me.destroy');

            // Retention (Phase 3): the account's footprint across restaurants.
            Route::prefix('me')->name('me.')->group(function () {
                Route::get('orders', [MyOrdersController::class, 'index'])->name('orders.index');
                Route::get('orders/{number}', [MyOrdersController::class, 'show'])
                    ->where('number', '[A-Za-z0-9-]+')
                    ->name('orders.show');
                Route::get('addresses', [AddressesController::class, 'index'])->name('addresses.index');
                Route::post('addresses', [AddressesController::class, 'store'])->name('addresses.store');
                Route::patch('addresses/{address}', [AddressesController::class, 'update'])->name('addresses.update');
                Route::delete('addresses/{address}', [AddressesController::class, 'destroy'])->name('addresses.destroy');
                Route::get('wallet', [WalletController::class, 'index'])->name('wallet');
                Route::get('favorites', [FavoritesController::class, 'index'])->name('favorites');
                Route::post('devices', [DevicesController::class, 'store'])->name('devices.store');
                Route::delete('devices', [DevicesController::class, 'destroy'])->name('devices.destroy');
            });
        });

        // Discovery is public; guests browse and only sign in to order.
        Route::get('restaurants', [RestaurantsController::class, 'index'])->name('restaurants.index');

        Route::prefix('restaurants/{restaurant}')
            ->name('restaurants.')
            ->middleware('tenant.route')
            ->group(function () {
                Route::get('/', [RestaurantsController::class, 'show'])->name('show');
                Route::get('menu', [RestaurantsController::class, 'menu'])->name('menu');

                // Guest or signed-in: the bearer token is honoured when present
                // and X-Cart-Token identifies a guest cart.
                Route::middleware('auth.optional')->group(function () {
                    Route::get('cart', [CartController::class, 'show'])->name('cart.show');
                    Route::post('cart/items/{menuItem}', [CartController::class, 'addItem'])->name('cart.add');
                    Route::patch('cart/items/{cartItem}', [CartController::class, 'updateItem'])->name('cart.update');
                    Route::put('cart/items/{cartItem}', [CartController::class, 'replaceItem'])->name('cart.replace');
                    Route::delete('cart/items/{cartItem}', [CartController::class, 'removeItem'])->name('cart.remove');
                    Route::delete('cart', [CartController::class, 'clear'])->name('cart.clear');

                    // The storefront's own quote + address controllers: same
                    // validation, same throttles, JSON already.
                    Route::middleware('throttle:60,1')->group(function () {
                        Route::post('checkout/address/suggest', [AddressLookupController::class, 'suggest'])->name('checkout.address.suggest');
                        Route::post('checkout/address/resolve', [AddressLookupController::class, 'resolve'])->name('checkout.address.resolve');
                        Route::post('checkout/delivery-quote', DeliveryQuoteController::class)->name('checkout.deliveryQuote');
                    });

                    Route::middleware('throttle:api-checkout')->group(function () {
                        Route::post('checkout/intents', [CheckoutController::class, 'intents'])->name('checkout.intents');
                        Route::post('checkout/{pendingCheckout}/confirm', [CheckoutController::class, 'confirm'])->name('checkout.confirm');
                    });

                    Route::get('orders/{number}', [OrdersController::class, 'show'])
                        ->where('number', '[A-Za-z0-9-]+')
                        ->name('orders.show');
                    Route::post('orders/{number}/reorder', [OrdersController::class, 'reorder'])
                        ->where('number', '[A-Za-z0-9-]+')
                        ->name('orders.reorder');
                });

                // Per-restaurant state of the signed-in customer.
                Route::middleware(['auth:sanctum', 'abilities:customer'])->group(function () {
                    Route::get('me', [RestaurantMembershipController::class, 'show'])->name('membership.show');
                    Route::put('favorite', [RestaurantMembershipController::class, 'favorite'])->name('membership.favorite');
                    Route::delete('favorite', [RestaurantMembershipController::class, 'unfavorite'])->name('membership.unfavorite');
                    Route::put('marketing-consent', [RestaurantMembershipController::class, 'marketingConsent'])->name('membership.marketing');
                });
            });

        /*
         * Operator API (§16, docs/plateful_platform_api_plan.md): the restaurant's
         * own staff and its machines. Signed by a Sanctum token carrying the
         * `operator` ability or by an API key (`pfk_…`); ApiActor answers
         * access questions for both. Restaurants resolve for the actor, live
         * or not, and anything out of reach is a 404.
         */
        Route::prefix('operator')
            ->name('operator.')
            ->middleware(['auth:sanctum,api-key', 'operator', 'throttle:api-operator'])
            ->group(function () {
                Route::get('me', [OperatorMeController::class, 'show'])->name('me');
                Route::get('restaurants', [OperatorRestaurantsController::class, 'index'])->name('restaurants.index');

                Route::prefix('restaurants/{restaurant}')
                    ->name('restaurants.')
                    ->middleware('operator.restaurant')
                    ->group(function () {
                        Route::get('/', [OperatorRestaurantsController::class, 'show'])
                            ->middleware('operator.scope:restaurants:read')
                            ->name('show');

                        Route::middleware('operator.scope:orders:read')->group(function () {
                            Route::get('orders', [OperatorOrdersController::class, 'index'])->name('orders.index');
                            Route::get('orders/{order:number}', [OperatorOrdersController::class, 'show'])->name('orders.show');
                            Route::get('kitchen', [OperatorOrdersController::class, 'kitchen'])->name('kitchen');
                        });
                        Route::post('orders/{order:number}/transition', [OperatorOrdersController::class, 'transition'])
                            ->middleware('operator.scope:orders:write')
                            ->name('orders.transition');

                        Route::get('menu', [OperatorMenuController::class, 'index'])
                            ->middleware('operator.scope:menu:read')
                            ->name('menu');
                        Route::patch('menu-items/{menuItem}/availability', [OperatorMenuController::class, 'availability'])
                            ->middleware('operator.scope:menu:write')
                            ->name('menu.availability');

                        Route::get('customers', [OperatorCustomersController::class, 'index'])
                            ->middleware('operator.scope:customers:read')
                            ->name('customers.index');

                        Route::middleware('operator.scope:api-keys:manage')->group(function () {
                            Route::get('api-keys', [OperatorApiKeysController::class, 'index'])->name('api-keys.index');
                            Route::post('api-keys', [OperatorApiKeysController::class, 'store'])->name('api-keys.store');
                            Route::delete('api-keys/{apiKey}', [OperatorApiKeysController::class, 'destroy'])
                                ->whereNumber('apiKey')
                                ->name('api-keys.destroy');
                        });
                    });
            });
    });

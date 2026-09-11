<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SocialLoginController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\OrdersController;
use App\Http\Controllers\Api\V1\RestaurantsController;
use App\Http\Controllers\Storefront\AddressLookupController;
use App\Http\Controllers\Storefront\DeliveryQuoteController;
use Illuminate\Support\Facades\Route;

/*
 * Public API for the Plateful mobile app — stateless JSON with Sanctum bearer
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
            Route::delete('me', [MeController::class, 'destroy'])->name('me.destroy');
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
                });
            });
    });

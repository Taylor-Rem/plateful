<?php

use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant')->group(function () {
    Route::get('/', HomeController::class)->name('storefront.home');

    Route::post('cart/items/{menuItem}', [CartController::class, 'addItem'])
        ->name('storefront.cart.add');
    Route::patch('cart/items/{cartItem}', [CartController::class, 'updateItem'])
        ->name('storefront.cart.update');
    Route::delete('cart/items/{cartItem}', [CartController::class, 'removeItem'])
        ->name('storefront.cart.remove');
    Route::delete('cart', [CartController::class, 'clear'])
        ->name('storefront.cart.clear');

    Route::get('checkout', [CheckoutController::class, 'show'])
        ->name('storefront.checkout.show');
    Route::post('orders', [CheckoutController::class, 'store'])
        ->name('storefront.orders.store');
    Route::get('orders/{number}', [OrderController::class, 'show'])
        ->where('number', '[A-Za-z0-9-]+')
        ->name('storefront.orders.show');

    Route::middleware('auth')->group(function () {
        Route::inertia('dashboard', 'Dashboard')->name('dashboard');

        Route::redirect('settings', '/settings/profile');
        Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    });

    Route::middleware(['auth', 'verified'])->group(function () {
        Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
        Route::get('settings/security', [SecurityController::class, 'edit'])->name('security.edit');
        Route::put('settings/password', [SecurityController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('user-password.update');
        Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');
    });
});

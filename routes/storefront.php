<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Storefront\Account\AddressesController;
use App\Http\Controllers\Storefront\Account\LoyaltyController;
use App\Http\Controllers\Storefront\Account\MyPlatefulController;
use App\Http\Controllers\Storefront\Account\OrdersController as AccountOrdersController;
use App\Http\Controllers\Storefront\Account\PasswordController as AccountPasswordController;
use App\Http\Controllers\Storefront\Account\ProfileController as AccountProfileController;
use App\Http\Controllers\Storefront\AccountController;
use App\Http\Controllers\Storefront\AddressLookupController;
use App\Http\Controllers\Storefront\Admin\MenuItemController as AdminMenuItemController;
use App\Http\Controllers\Storefront\Admin\PhotoController as AdminPhotoController;
use App\Http\Controllers\Storefront\Admin\SiteController as AdminSiteController;
use App\Http\Controllers\Storefront\CartController;
use App\Http\Controllers\Storefront\CheckoutController;
use App\Http\Controllers\Storefront\DeliveryQuoteController;
use App\Http\Controllers\Storefront\HomeController;
use App\Http\Controllers\Storefront\MenuController;
use App\Http\Controllers\Storefront\OrderController;
use App\Http\Controllers\Storefront\PreviewController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant')->group(function () {
    Route::get('/', HomeController::class)->name('storefront.home');
    Route::get('/menu', MenuController::class)->name('storefront.menu');

    // Storefront-side landing for the Google login handoff. Not behind 'auth':
    // it verifies the one-time token from the platform-host callback and logs
    // the customer in on this storefront host.
    Route::get('auth/google/finish', [GoogleController::class, 'finish'])
        ->name('auth.google.finish');

    // Owner-preview handoff from the admin console. Also token-gated, not
    // 'auth' — ResolveTenant whitelists this route pre-live so the token can
    // establish the session that the rest of the preview relies on.
    Route::get('preview/enter', [PreviewController::class, 'enter'])
        ->name('storefront.preview.enter');

    Route::post('cart/items/{menuItem}', [CartController::class, 'addItem'])
        ->name('storefront.cart.add');
    Route::patch('cart/items/{cartItem}', [CartController::class, 'updateItem'])
        ->name('storefront.cart.update');
    Route::delete('cart/items/{cartItem}', [CartController::class, 'removeItem'])
        ->name('storefront.cart.remove');
    Route::delete('cart', [CartController::class, 'clear'])
        ->name('storefront.cart.clear');

    // Places proxy for the checkout address field. Throttled because it is
    // public and every call bills at Google — an unmetered proxy is somebody
    // else's free geocoding API.
    Route::middleware('throttle:60,1')->group(function (): void {
        Route::post('checkout/address/suggest', [AddressLookupController::class, 'suggest'])
            ->name('storefront.checkout.address.suggest');
        Route::post('checkout/address/resolve', [AddressLookupController::class, 'resolve'])
            ->name('storefront.checkout.address.resolve');

        // The live quote that gates checkout. Throttled alongside the address
        // lookups: it calls a delivery provider on every address change.
        Route::post('checkout/delivery-quote', DeliveryQuoteController::class)
            ->name('storefront.checkout.deliveryQuote');
    });

    Route::get('checkout', [CheckoutController::class, 'show'])
        ->name('storefront.checkout.show');
    Route::get('checkout/return', [CheckoutController::class, 'paymentReturn'])
        ->name('storefront.checkout.return');
    // Throttled: every hit creates a real Stripe Checkout Session (and, live,
    // a PaymentIntent) — an unmetered endpoint is a card-testing target the
    // moment live keys are configured. 10/min per session is far above any
    // legitimate checkout retry rate.
    Route::post('orders', [CheckoutController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('storefront.orders.store');
    Route::get('orders/{number}', [OrderController::class, 'show'])
        ->where('number', '[A-Za-z0-9-]+')
        ->name('storefront.orders.show');

    // Admin-only menu editing on the storefront. The policy gates per-action;
    // unauthenticated users hit auth middleware first.
    Route::middleware('auth')->prefix('admin/menu')->name('storefront.admin.menu.')->group(function () {
        Route::post('items', [AdminMenuItemController::class, 'store'])->name('items.store');
        Route::put('items/{menuItem}', [AdminMenuItemController::class, 'update'])->name('items.update');
        Route::delete('items/{menuItem}', [AdminMenuItemController::class, 'destroy'])->name('items.destroy');
    });

    Route::middleware('auth')->prefix('admin/site')->name('storefront.admin.site.')->group(function () {
        Route::post('hero', [AdminSiteController::class, 'updateHero'])->name('hero.update');
        Route::post('about', [AdminSiteController::class, 'updateAbout'])->name('about.update');
        Route::post('social', [AdminSiteController::class, 'updateSocial'])->name('social.update');

        Route::post('photos', [AdminPhotoController::class, 'store'])->name('photos.store');
        Route::post('photos/reorder', [AdminPhotoController::class, 'reorder'])->name('photos.reorder');
        Route::patch('photos/{photo}', [AdminPhotoController::class, 'update'])->name('photos.update');
        Route::delete('photos/{photo}', [AdminPhotoController::class, 'destroy'])->name('photos.destroy');
    });

    Route::middleware('auth')->group(function () {
        Route::redirect('settings', '/settings/profile');
        Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');

        Route::prefix('account')->name('storefront.account.')->group(function () {
            Route::get('/', [AccountController::class, 'show'])->name('show');

            Route::get('/orders', [AccountOrdersController::class, 'index'])->name('orders.index');
            Route::get('/orders/{number}', [AccountOrdersController::class, 'show'])
                ->where('number', '[A-Za-z0-9-]+')
                ->name('orders.show');

            Route::get('/addresses', [AddressesController::class, 'index'])->name('addresses.index');
            Route::post('/addresses', [AddressesController::class, 'store'])->name('addresses.store');
            Route::patch('/addresses/{address}', [AddressesController::class, 'update'])->name('addresses.update');
            Route::delete('/addresses/{address}', [AddressesController::class, 'destroy'])->name('addresses.destroy');

            Route::get('/loyalty', [LoyaltyController::class, 'show'])->name('loyalty.show');

            Route::get('/my-plateful', [MyPlatefulController::class, 'show'])->name('myPlateful.show');

            Route::get('/profile', [AccountProfileController::class, 'edit'])->name('profile.edit');
            Route::patch('/profile', [AccountProfileController::class, 'update'])->name('profile.update');

            Route::get('/password', [AccountPasswordController::class, 'edit'])->name('password.edit');
            Route::patch('/password', [AccountPasswordController::class, 'update'])->name('password.update');
        });
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

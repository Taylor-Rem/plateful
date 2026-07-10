<?php

use App\Http\Controllers\Admin\AdminHomeController;
use App\Http\Controllers\Admin\AdminInvitationController;
use App\Http\Controllers\Admin\SuperAdmin;
use App\Http\Controllers\Admin\TenantAdmin;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::domain('admin.'.config('platform.primary_domain'))->group(function () {
    // Stripe Connect webhooks. Public (no auth), CSRF-exempt via
    // bootstrap/app.php, signature-verified in the controller.
    Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

    Route::middleware('guest')->group(function () {
        Route::get('/invitations/{token}', [AdminInvitationController::class, 'show'])->name('admin.invitations.show');
        Route::post('/invitations/{token}', [AdminInvitationController::class, 'accept'])->name('admin.invitations.accept');
    });

    Route::middleware('admin')->group(function () {
        Route::get('/', AdminHomeController::class)->name('admin.home');

        Route::prefix('{restaurant}')->middleware('admin.restaurant')->name('admin.restaurant.')->group(function () {
            // Routes available to any restaurant member (admin OR staff)
            Route::get('/dashboard', TenantAdmin\DashboardController::class)->name('dashboard');
            Route::get('/menu', [TenantAdmin\MenuController::class, 'index'])->name('menu.index');

            Route::get('/orders', [TenantAdmin\OrdersController::class, 'index'])->name('orders.index');
            Route::get('/orders/{order:number}', [TenantAdmin\OrdersController::class, 'show'])->name('orders.show');
            Route::post('/orders/{order:number}/transitions', [TenantAdmin\OrdersController::class, 'transition'])->name('orders.transition');

            Route::get('/kitchen', [TenantAdmin\KitchenController::class, 'index'])->name('kitchen.index');

            Route::get('/hours', [TenantAdmin\HoursController::class, 'edit'])->name('hours.edit');
            Route::put('/hours', [TenantAdmin\HoursController::class, 'update'])->name('hours.update');

            // Routes restricted to restaurant admins
            Route::middleware('admin.restaurant.admin')->group(function () {
                Route::get('/onboarding', [TenantAdmin\OnboardingController::class, 'show'])->name('onboarding.show');
                Route::post('/onboarding/custom-domain', [TenantAdmin\OnboardingController::class, 'requestCustomDomain'])->name('onboarding.customDomain');
                Route::post('/onboarding/go-live', [TenantAdmin\OnboardingController::class, 'goLive'])->name('onboarding.goLive');

                Route::post('/onboarding/stripe/connect', [TenantAdmin\StripeConnectController::class, 'start'])->name('onboarding.stripe.connect');
                Route::get('/onboarding/stripe/return', [TenantAdmin\StripeConnectController::class, 'return'])->name('onboarding.stripe.return');
                Route::get('/onboarding/stripe/refresh', [TenantAdmin\StripeConnectController::class, 'refresh'])->name('onboarding.stripe.refresh');
                Route::get('/onboarding/stripe/dashboard', [TenantAdmin\StripeConnectController::class, 'dashboard'])->name('onboarding.stripe.dashboard');

                Route::get('/payouts', [TenantAdmin\PayoutsController::class, 'index'])->name('payouts.index');

                Route::get('/settings/pos', [TenantAdmin\PosIntegrationsController::class, 'show'])->name('pos.show');

                Route::post('/menu/categories', [TenantAdmin\MenuCategoryController::class, 'store'])->name('categories.store');
                Route::post('/menu/categories/reorder', [TenantAdmin\MenuCategoryController::class, 'reorder'])->name('categories.reorder');
                Route::put('/menu/categories/{category}', [TenantAdmin\MenuCategoryController::class, 'update'])->name('categories.update');
                Route::delete('/menu/categories/{category}', [TenantAdmin\MenuCategoryController::class, 'destroy'])->name('categories.destroy');

                Route::get('/menu/templates', [TenantAdmin\ItemTemplateController::class, 'index'])->name('templates.index');
                Route::get('/menu/templates/create', [TenantAdmin\ItemTemplateController::class, 'create'])->name('templates.create');
                Route::post('/menu/templates', [TenantAdmin\ItemTemplateController::class, 'store'])->name('templates.store');
                Route::get('/menu/templates/{template}/edit', [TenantAdmin\ItemTemplateController::class, 'edit'])->name('templates.edit');
                Route::put('/menu/templates/{template}', [TenantAdmin\ItemTemplateController::class, 'update'])->name('templates.update');
                Route::delete('/menu/templates/{template}', [TenantAdmin\ItemTemplateController::class, 'destroy'])->name('templates.destroy');

                Route::get('/settings', [TenantAdmin\SettingsController::class, 'edit'])->name('settings.edit');
                Route::put('/settings', [TenantAdmin\SettingsController::class, 'update'])->name('settings.update');

                Route::get('/members', [TenantAdmin\MembersController::class, 'index'])->name('members.index');
                Route::put('/members/{member}', [TenantAdmin\MembersController::class, 'update'])->name('members.update');
                Route::delete('/members/{member}', [TenantAdmin\MembersController::class, 'destroy'])->name('members.destroy');

                Route::post('/invitations', [TenantAdmin\InvitationController::class, 'store'])->name('invitations.store');
                Route::delete('/invitations/{invitation}', [TenantAdmin\InvitationController::class, 'destroy'])->name('invitations.destroy');
            });
        });

        Route::prefix('super')->middleware('super')->name('admin.super.')->group(function () {
            Route::get('/restaurants', [SuperAdmin\RestaurantsController::class, 'index'])->name('restaurants.index');
            Route::get('/restaurants/create', [SuperAdmin\RestaurantsController::class, 'create'])->name('restaurants.create');
            Route::post('/restaurants', [SuperAdmin\RestaurantsController::class, 'store'])->name('restaurants.store');
            Route::get('/restaurants/{restaurant}', [SuperAdmin\RestaurantsController::class, 'show'])->name('restaurants.show');
            Route::put('/restaurants/{restaurant}/fee', [SuperAdmin\RestaurantsController::class, 'updateFee'])->name('restaurants.updateFee');
            Route::post('/restaurants/{restaurant}/deactivate', [SuperAdmin\RestaurantsController::class, 'deactivate'])->name('restaurants.deactivate');
            Route::post('/restaurants/{restaurant}/activate', [SuperAdmin\RestaurantsController::class, 'activate'])->name('restaurants.activate');

            Route::get('/admins', [SuperAdmin\AdminsController::class, 'index'])->name('admins.index');
            Route::post('/admins/invitations', [SuperAdmin\InvitationController::class, 'store'])->name('admins.invitations.store');
        });
    });
});

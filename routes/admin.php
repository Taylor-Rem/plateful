<?php

use App\Http\Controllers\Admin\AdminHomeController;
use App\Http\Controllers\Admin\AdminInvitationController;
use App\Http\Controllers\Admin\AdminLoginHandoffController;
use App\Http\Controllers\Admin\AdminSecurityController;
use App\Http\Controllers\Admin\TenantAdmin;
use Illuminate\Support\Facades\Route;

/*
 * The admin console host (admin.<primary domain>): login handoff, invitation
 * acceptance, the restaurant picker, and the per-restaurant tenant admin.
 * Platform-level routes live in routes/super-admin.php (registered before
 * this file so /super/* is never captured by the {restaurant} wildcard);
 * webhook endpoints on this host live in routes/webhooks.php.
 */
Route::domain('admin.'.config('platform.primary_domain'))->group(function () {
    // Cross-host login handoff (e.g. straight after owner signup on the
    // primary host). Token-gated, so no auth middleware.
    Route::get('/auth/handoff', AdminLoginHandoffController::class)->name('admin.auth.handoff');

    Route::middleware('guest')->group(function () {
        Route::get('/invitations/{token}', [AdminInvitationController::class, 'show'])->name('admin.invitations.show');
        Route::post('/invitations/{token}', [AdminInvitationController::class, 'accept'])->name('admin.invitations.accept');
    });

    // Account security stays outside the two-factor.required group below —
    // it is where an un-enrolled super admin is redirected to enroll, so
    // guarding it would loop. Registered before the {restaurant} wildcard
    // prefix so /security is never captured as a subdomain.
    Route::middleware('admin')->group(function () {
        Route::get('/security', [AdminSecurityController::class, 'edit'])->name('admin.security.edit');
        Route::put('/security/password', [AdminSecurityController::class, 'updatePassword'])
            ->middleware('throttle:6,1')
            ->name('admin.security.password.update');
    });

    Route::middleware(['admin', 'two-factor.required'])->group(function () {
        Route::get('/', AdminHomeController::class)->name('admin.home');

        // Square posts back to a single registered redirect URI (not scoped to
        // a restaurant path); the restaurant is carried in the OAuth `state`.
        Route::get('/pos/square/callback', [TenantAdmin\SquareConnectController::class, 'callback'])
            ->name('admin.pos.square.callback');
        Route::get('/pos/clover/callback', [TenantAdmin\CloverConnectController::class, 'callback'])
            ->name('admin.pos.clover.callback');

        // The :subdomain field keeps Wayfinder's generated helpers (and
        // route() model interpolation) building subdomain URLs; runtime
        // resolution already goes through the explicit Route::bind in
        // AppServiceProvider either way.
        Route::prefix('{restaurant:subdomain}')->middleware('admin.restaurant')->name('admin.restaurant.')->group(function () {
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
                Route::put('/onboarding/basics', [TenantAdmin\OnboardingController::class, 'updateBasics'])->name('onboarding.basics');
                Route::put('/onboarding/refund-policy', [TenantAdmin\OnboardingController::class, 'updateRefundPolicy'])->name('onboarding.refundPolicy');
                Route::post('/onboarding/menu-preset', [TenantAdmin\OnboardingController::class, 'applyMenuPreset'])->name('onboarding.menuPreset');
                Route::get('/onboarding/preview', [TenantAdmin\OnboardingController::class, 'preview'])->name('onboarding.preview');
                Route::post('/onboarding/custom-domain', [TenantAdmin\OnboardingController::class, 'requestCustomDomain'])->name('onboarding.customDomain');
                Route::post('/onboarding/go-live', [TenantAdmin\OnboardingController::class, 'goLive'])->name('onboarding.goLive');

                Route::post('/menu-import', [TenantAdmin\MenuImportController::class, 'store'])->name('menuImport.store');
                Route::get('/menu-import/{menuImport}/review', [TenantAdmin\MenuImportController::class, 'review'])->name('menuImport.review');
                Route::post('/menu-import/{menuImport}/confirm', [TenantAdmin\MenuImportController::class, 'confirm'])->name('menuImport.confirm');
                Route::post('/menu-import/{menuImport}/discard', [TenantAdmin\MenuImportController::class, 'discard'])->name('menuImport.discard');

                // Stripe Connect account management. Started during onboarding
                // but used for the life of the account (Express dashboard link,
                // re-onboarding refresh), so it lives outside /onboarding.
                Route::post('/stripe/connect', [TenantAdmin\StripeConnectController::class, 'start'])->name('stripe.connect');
                Route::get('/stripe/return', [TenantAdmin\StripeConnectController::class, 'return'])->name('stripe.return');
                Route::get('/stripe/refresh', [TenantAdmin\StripeConnectController::class, 'refresh'])->name('stripe.refresh');
                Route::get('/stripe/dashboard', [TenantAdmin\StripeConnectController::class, 'dashboard'])->name('stripe.dashboard');

                Route::get('/payouts', [TenantAdmin\PayoutsController::class, 'index'])->name('payouts.index');

                // Customer list + export are owner-level data (contact info for
                // the whole list), so they sit with Settings/Payouts, not staff.
                Route::get('/customers', [TenantAdmin\CustomersController::class, 'index'])->name('customers.index');
                Route::get('/customers/export', [TenantAdmin\CustomersController::class, 'export'])->name('customers.export');

                Route::get('/settings/pos', [TenantAdmin\PosIntegrationsController::class, 'show'])->name('pos.show');
                Route::post('/settings/pos/square/connect', [TenantAdmin\SquareConnectController::class, 'connect'])->name('pos.square.connect');
                Route::post('/settings/pos/square/disconnect', [TenantAdmin\SquareConnectController::class, 'disconnect'])->name('pos.square.disconnect');
                Route::post('/settings/pos/clover/connect', [TenantAdmin\CloverConnectController::class, 'connect'])->name('pos.clover.connect');
                Route::post('/settings/pos/clover/disconnect', [TenantAdmin\CloverConnectController::class, 'disconnect'])->name('pos.clover.disconnect');

                Route::get('/settings/delivery', [TenantAdmin\DeliveryIntegrationsController::class, 'show'])->name('delivery.show');
                Route::put('/settings/delivery', [TenantAdmin\DeliveryIntegrationsController::class, 'updateSettings'])->name('delivery.settings.update');

                // Both couriers are one-click: no credential form, so the "save"
                // action provisions the provider-side identity behind the scenes
                // (Uber sub-organization, DoorDash Business/Store). Named
                // `.save`/`.disconnect` to match the card's saveUrl/disconnectUrl
                // convention in DeliveryIntegrationsController::show.
                Route::post('/settings/delivery/uber', [TenantAdmin\DeliveryIntegrationsController::class, 'enableUber'])->name('delivery.uber.save');
                Route::post('/settings/delivery/uber/disconnect', [TenantAdmin\DeliveryIntegrationsController::class, 'disconnectUber'])->name('delivery.uber.disconnect');
                Route::post('/settings/delivery/doordash', [TenantAdmin\DeliveryIntegrationsController::class, 'enableDoorDash'])->name('delivery.doordash.save');
                Route::post('/settings/delivery/doordash/disconnect', [TenantAdmin\DeliveryIntegrationsController::class, 'disconnectDoorDash'])->name('delivery.doordash.disconnect');

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
    });
});

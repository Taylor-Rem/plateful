<?php

use App\Http\Controllers\Admin\SuperAdmin;
use Illuminate\Support\Facades\Route;

/*
 * Platform-level super admin console on the admin host. Registered BEFORE
 * routes/admin.php (see bootstrap/app.php) so /super/* can never be captured
 * by the tenant {restaurant} wildcard prefix. The `admin` middleware supplies
 * the guest-to-login redirect; `super` enforces the platform role.
 */
Route::domain('admin.'.config('platform.primary_domain'))
    ->prefix('super')
    ->middleware(['admin', 'super'])
    ->name('admin.super.')
    ->group(function () {
        Route::get('/restaurants', [SuperAdmin\RestaurantsController::class, 'index'])->name('restaurants.index');
        Route::get('/restaurants/create', [SuperAdmin\RestaurantsController::class, 'create'])->name('restaurants.create');
        Route::post('/restaurants', [SuperAdmin\RestaurantsController::class, 'store'])->name('restaurants.store');
        Route::get('/restaurants/{restaurant:subdomain}', [SuperAdmin\RestaurantsController::class, 'show'])->name('restaurants.show');
        Route::put('/restaurants/{restaurant:subdomain}/fee', [SuperAdmin\RestaurantsController::class, 'updateFee'])->name('restaurants.updateFee');
        Route::put('/restaurants/{restaurant:subdomain}/roles', [SuperAdmin\RestaurantsController::class, 'updateRoles'])->name('restaurants.updateRoles');
        Route::post('/restaurants/{restaurant:subdomain}/deactivate', [SuperAdmin\RestaurantsController::class, 'deactivate'])->name('restaurants.deactivate');
        Route::post('/restaurants/{restaurant:subdomain}/activate', [SuperAdmin\RestaurantsController::class, 'activate'])->name('restaurants.activate');
        // Soft delete uses the standard {restaurant} binding (the restaurant is
        // still live at this point). Restore / permanent delete operate on an
        // already-trashed row, which the {restaurant} binding excludes, so they
        // take the raw {subdomain} and resolve withTrashed() in the controller.
        Route::delete('/restaurants/{restaurant:subdomain}', [SuperAdmin\RestaurantsController::class, 'destroy'])->name('restaurants.destroy');
        Route::post('/restaurants/{subdomain}/restore', [SuperAdmin\RestaurantsController::class, 'restore'])->name('restaurants.restore');
        Route::delete('/restaurants/{subdomain}/force', [SuperAdmin\RestaurantsController::class, 'forceDelete'])->name('restaurants.forceDelete');

        Route::get('/earnings', [SuperAdmin\EarningsController::class, 'index'])->name('earnings.index');
        Route::put('/platform-roles', [SuperAdmin\PlatformRolesController::class, 'update'])->name('platformRoles.update');

        Route::get('/admins', [SuperAdmin\AdminsController::class, 'index'])->name('admins.index');
        Route::post('/admins/invitations', [SuperAdmin\InvitationController::class, 'store'])->name('admins.invitations.store');
    });

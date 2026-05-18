<?php

use App\Http\Controllers\Admin\AdminHomeController;
use App\Http\Controllers\Admin\AdminInvitationController;
use App\Http\Controllers\Admin\SuperAdmin;
use App\Http\Controllers\Admin\TenantAdmin;
use Illuminate\Support\Facades\Route;

Route::domain('admin.'.config('platform.primary_domain'))->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('/invitations/{token}', [AdminInvitationController::class, 'show'])->name('admin.invitations.show');
        Route::post('/invitations/{token}', [AdminInvitationController::class, 'accept'])->name('admin.invitations.accept');
    });

    Route::middleware('admin')->group(function () {
        Route::get('/', AdminHomeController::class)->name('admin.home');

        Route::prefix('{restaurant}')->middleware('admin.restaurant')->name('admin.restaurant.')->group(function () {
            Route::get('/dashboard', TenantAdmin\DashboardController::class)->name('dashboard');
            Route::get('/menu', [TenantAdmin\MenuController::class, 'index'])->name('menu.index');
            Route::get('/orders', [TenantAdmin\OrdersController::class, 'index'])->name('orders.index');
            Route::get('/settings', [TenantAdmin\SettingsController::class, 'edit'])->name('settings.edit');
            Route::post('/invitations', [TenantAdmin\InvitationController::class, 'store'])->name('invitations.store');
        });

        Route::prefix('super')->middleware('super')->name('admin.super.')->group(function () {
            Route::get('/restaurants', [SuperAdmin\RestaurantsController::class, 'index'])->name('restaurants.index');
            Route::get('/admins', [SuperAdmin\AdminsController::class, 'index'])->name('admins.index');
            Route::post('/admins/invitations', [SuperAdmin\InvitationController::class, 'store'])->name('admins.invitations.store');
        });
    });
});

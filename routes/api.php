<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\PasswordResetLinkController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\SocialLoginController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\MeController;
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
    });

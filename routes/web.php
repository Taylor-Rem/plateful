<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\OwnerSignupController;
use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::domain(config('platform.primary_domain'))->group(function () {
    /*
    |---------------------------------------------------------------------------
    | Sign in with Google
    |---------------------------------------------------------------------------
    |
    | Google permits a single, non-wildcard redirect URI, so both OAuth routes
    | live on the platform host. The storefront a customer started from is
    | carried through and they are handed back to it after login.
    |
    */
    Route::prefix('auth/google')->name('auth.google.')->group(function () {
        Route::get('redirect', [GoogleController::class, 'redirect'])->name('redirect');
        Route::get('callback', [GoogleController::class, 'callback'])->name('callback');
    });

    Route::get('/', function (Request $request) {
        $scheme = $request->getScheme();

        $restaurants = Restaurant::query()
            ->public()
            ->orderBy('name')
            ->get()
            ->map(fn (Restaurant $restaurant) => [
                'name' => $restaurant->name,
                'description' => $restaurant->description,
                'city' => $restaurant->city,
                'state' => $restaurant->state,
                'logoUrl' => $restaurant->logoThumbUrl(),
                'url' => $restaurant->publicUrl($scheme),
            ]);

        return Inertia::render('Welcome', [
            'adminUrl' => $scheme.'://admin.'.config('platform.primary_domain'),
            'restaurants' => $restaurants,
            'authUserName' => $request->user()?->name,
            'hasAdminAccess' => (bool) $request->user()?->isAdmin(),
        ]);
    })->name('home');

    Route::get('/terms', fn () => Inertia::render('Legal/Terms'))->name('terms');
    Route::get('/privacy', fn () => Inertia::render('Legal/Privacy'))->name('privacy');

    /*
    |---------------------------------------------------------------------------
    | Restaurant owner self-serve signup
    |---------------------------------------------------------------------------
    |
    | Lives on the root domain (not a tenant subdomain). Visitors can read the
    | owner-facing marketing page, submit a signup, and land on a "pending
    | review" page after submission.
    |
    */
    Route::prefix('for-restaurants')->name('owner-signup.')->group(function () {
        Route::get('/', [OwnerSignupController::class, 'landing'])->name('landing');
        Route::get('/signup', [OwnerSignupController::class, 'create'])->name('create');
        Route::post('/signup', [OwnerSignupController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('store');
    });
});

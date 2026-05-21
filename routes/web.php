<?php

use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::domain(config('platform.primary_domain'))->group(function () {
    Route::get('/', function (Request $request) {
        $scheme = $request->getScheme();

        $restaurants = Restaurant::query()
            ->where('is_active', true)
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
        ]);
    })->name('home');
});

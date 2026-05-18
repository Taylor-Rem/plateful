<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RestaurantSettingsRequest;
use App\Models\Restaurant;
use App\Services\RestaurantImageService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(Restaurant $restaurant): Response
    {
        return Inertia::render('Admin/TenantAdmin/Settings', [
            'restaurant' => RestaurantData::fromModel($restaurant),
        ]);
    }

    public function update(
        RestaurantSettingsRequest $request,
        Restaurant $restaurant,
        RestaurantImageService $images,
    ): RedirectResponse {
        $validated = $request->validated();

        $restaurant->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'primary_color' => $validated['primary_color'] ?? null,
            'secondary_color' => $validated['secondary_color'] ?? null,
            'email' => $validated['email'] ?? $restaurant->email,
            'phone' => $validated['phone'] ?? null,
        ]);

        if ($request->boolean('remove_logo') && $restaurant->logo_path) {
            $images->deleteVariants($restaurant->logo_path);
            $restaurant->logo_path = null;
        }

        if ($request->hasFile('logo')) {
            $restaurant->logo_path = $images->storeLogo($restaurant, $request->file('logo'));
        }

        $restaurant->save();

        return back()->with('success', 'Settings updated.');
    }
}

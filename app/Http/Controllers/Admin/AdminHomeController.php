<?php

namespace App\Http\Controllers\Admin;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminHomeController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $accessible = $user->accessibleRestaurants();

        if ($user->isSuperAdmin()) {
            return Inertia::render('Admin/Home', [
                'restaurants' => $accessible->map(fn ($r) => RestaurantData::fromModel($r))->all(),
                'isSuperAdmin' => true,
            ]);
        }

        if ($accessible->count() === 0) {
            return Inertia::render('Admin/NoAccess');
        }

        if ($accessible->count() === 1) {
            return redirect()->route('admin.restaurant.dashboard', [
                'restaurant' => $accessible->first()->subdomain,
            ]);
        }

        return Inertia::render('Admin/Home', [
            'restaurants' => $accessible->map(fn ($r) => RestaurantData::fromModel($r))->all(),
            'isSuperAdmin' => false,
        ]);
    }
}

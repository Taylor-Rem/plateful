<?php

namespace App\Http\Controllers\Admin;

use App\Data\RestaurantData;
use App\Enums\RestaurantStatus;
use App\Http\Controllers\Controller;
use App\Models\RestaurantSignup;
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
                'pendingSignupsCount' => RestaurantSignup::query()
                    ->where('status', RestaurantSignup::STATUS_PENDING)
                    ->count(),
            ]);
        }

        if ($accessible->count() === 0) {
            return Inertia::render('Admin/NoAccess');
        }

        if ($accessible->count() === 1) {
            $only = $accessible->first();

            // Owners whose restaurant is still in onboarding land on the
            // wizard instead of the dashboard.
            if ($only->status === RestaurantStatus::Approved) {
                return redirect()->route('admin.restaurant.onboarding.show', [
                    'restaurant' => $only->subdomain,
                ]);
            }

            return redirect()->route('admin.restaurant.dashboard', [
                'restaurant' => $only->subdomain,
            ]);
        }

        return Inertia::render('Admin/Home', [
            'restaurants' => $accessible->map(fn ($r) => RestaurantData::fromModel($r))->all(),
            'isSuperAdmin' => false,
        ]);
    }
}

<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\Account\UpdateProfileRequest;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request, CurrentTenant $tenant): Response
    {
        $user = $request->user();

        return Inertia::render('Storefront/Account/Profile', [
            'restaurant' => RestaurantData::fromModel($tenant->get()),
            'profile' => [
                'name' => (string) $user->name,
                'email' => (string) $user->email,
                'phone' => $user->phone,
            ],
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('storefront.account.profile.edit');
    }
}

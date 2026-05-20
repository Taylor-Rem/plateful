<?php

namespace App\Http\Controllers\Storefront\Account;

use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storefront\Account\UpdatePasswordRequest;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordController extends Controller
{
    public function edit(Request $request, CurrentTenant $tenant): Response
    {
        return Inertia::render('Storefront/Account/Password', [
            'restaurant' => RestaurantData::fromModel($tenant->get()),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->validated('password'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return to_route('storefront.account.password.edit');
    }
}

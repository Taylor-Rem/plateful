<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Enums\RestaurantRole;
use App\Http\Controllers\Controller;
use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class InvitationController extends Controller
{
    public function store(Request $request, Restaurant $restaurant): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(RestaurantRole::class)],
        ]);

        $invitation = AdminInvitation::create([
            'email' => $data['email'],
            'restaurant_id' => $restaurant->id,
            'role' => $data['role'],
            'as_super_admin' => false,
            'token' => AdminInvitation::generateToken(),
            'invited_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->queue(new AdminInvitationMail($invitation));

        return back()->with('success', "Invitation sent to {$invitation->email}.");
    }

    public function destroy(Restaurant $restaurant, AdminInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->restaurant_id !== $restaurant->id, 404);

        $invitation->delete();

        return back()->with('success', 'Invitation revoked.');
    }
}

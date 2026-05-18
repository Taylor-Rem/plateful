<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Http\Controllers\Controller;
use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class InvitationController extends Controller
{
    public function store(Request $request, Restaurant $restaurant): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $invitation = AdminInvitation::create([
            'email' => $data['email'],
            'restaurant_id' => $restaurant->id,
            'as_super_admin' => false,
            'token' => AdminInvitation::generateToken(),
            'invited_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->queue(new AdminInvitationMail($invitation));

        return back()->with('status', "Invitation sent to {$invitation->email}.");
    }
}

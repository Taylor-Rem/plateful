<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class InvitationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'as_super_admin' => ['nullable', 'boolean'],
        ]);

        $invitation = AdminInvitation::create([
            'email' => $data['email'],
            'restaurant_id' => $data['restaurant_id'] ?? null,
            'as_super_admin' => (bool) ($data['as_super_admin'] ?? false),
            'token' => AdminInvitation::generateToken(),
            'invited_by_user_id' => $request->user()->id,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->queue(new AdminInvitationMail($invitation));

        return back()->with('status', "Invitation sent to {$invitation->email}.");
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AcceptInvitationRequest;
use App\Models\AdminInvitation;
use App\Services\AdminInvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AdminInvitationController extends Controller
{
    public function show(string $token): Response|RedirectResponse
    {
        $invitation = AdminInvitation::query()->where('token', $token)->first();

        if (! $invitation) {
            abort(404);
        }

        if ($invitation->accepted_at !== null) {
            return redirect()->route('login')->with('success', 'This invitation has already been accepted.');
        }

        if ($invitation->expires_at <= now()) {
            return Inertia::render('Admin/Invitations/Show', [
                'invitation' => null,
                'error' => 'This invitation has expired.',
            ]);
        }

        return Inertia::render('Admin/Invitations/Show', [
            'invitation' => [
                'token' => $invitation->token,
                'email' => $invitation->email,
                'restaurantName' => $invitation->restaurant?->name,
                'asSuperAdmin' => $invitation->as_super_admin,
                'role' => $invitation->role?->value,
            ],
            'error' => null,
        ]);
    }

    public function accept(AcceptInvitationRequest $request, string $token, AdminInvitationService $invitations): RedirectResponse
    {
        $invitation = AdminInvitation::query()->where('token', $token)->valid()->first();

        if (! $invitation) {
            abort(404);
        }

        $user = $invitations->accept(
            $invitation,
            $request->validated('name'),
            $request->validated('password'),
        );

        Auth::login($user);

        return redirect()->route('admin.home');
    }
}

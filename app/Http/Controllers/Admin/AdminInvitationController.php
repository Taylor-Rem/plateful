<?php

namespace App\Http\Controllers\Admin;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\AdminInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class AdminInvitationController extends Controller
{
    use PasswordValidationRules;

    public function show(string $token): Response|RedirectResponse
    {
        $invitation = AdminInvitation::query()->where('token', $token)->first();

        if (! $invitation) {
            abort(404);
        }

        if ($invitation->accepted_at !== null) {
            return redirect()->route('login')->with('status', 'This invitation has already been accepted.');
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

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = AdminInvitation::query()->where('token', $token)->valid()->first();

        if (! $invitation) {
            abort(404);
        }

        Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = DB::transaction(function () use ($invitation, $request) {
            $user = User::create([
                'name' => $request->input('name'),
                'email' => $invitation->email,
                'password' => $request->input('password'),
                'is_super_admin' => $invitation->as_super_admin,
            ]);

            if ($invitation->restaurant_id) {
                $user->restaurants()->attach($invitation->restaurant_id, [
                    'role' => $invitation->role?->value ?? 'admin',
                ]);
            }

            $invitation->forceFill([
                'accepted_at' => now(),
                'accepted_user_id' => $user->id,
            ])->save();

            return $user;
        });

        Auth::login($user);

        return redirect()->route('admin.home');
    }
}

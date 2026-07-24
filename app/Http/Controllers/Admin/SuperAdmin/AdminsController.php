<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuperAdmin\UpdateAdminRequest;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminsController extends Controller
{
    public function index(Request $request): Response
    {
        // An "admin" is any user who is super admin OR a member of a restaurant
        // via the restaurant_user pivot. (Customers who have only ordered are
        // not admins and are excluded.)
        $admins = User::query()
            ->where(function ($q) {
                $q->where('is_super_admin', true)
                    ->orWhereHas('restaurants');
            })
            ->with('restaurants:id,name,subdomain')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isSuperAdmin' => $user->is_super_admin,
                'restaurants' => $user->restaurants->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'subdomain' => $r->subdomain,
                ])->all(),
            ])
            ->all();

        $restaurants = Restaurant::query()
            ->orderBy('name')
            ->get(['id', 'name', 'subdomain'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'subdomain' => $r->subdomain,
            ])
            ->all();

        $pendingInvitations = AdminInvitation::query()
            ->valid()
            ->with(['restaurant:id,name', 'invitedBy:id,name'])
            ->latest('id')
            ->get()
            ->map(fn (AdminInvitation $invitation) => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'restaurantName' => $invitation->restaurant?->name,
                'asSuperAdmin' => $invitation->as_super_admin,
                'expiresAt' => $invitation->expires_at?->toIso8601String(),
                'invitedByName' => $invitation->invitedBy?->name,
            ])
            ->all();

        return Inertia::render('Admin/SuperAdmin/Admins', [
            'admins' => $admins,
            'restaurants' => $restaurants,
            'pendingInvitations' => $pendingInvitations,
            'currentUserId' => $request->user()->id,
        ]);
    }

    /**
     * Edit an admin's name and email. Changing the email changes how they sign
     * in, so we clear their verified status — they must re-verify the new
     * address — mirroring the self-service profile update.
     */
    public function update(UpdateAdminRequest $request, User $user): RedirectResponse
    {
        $validated = $request->validated();

        $user->name = $validated['name'];
        $user->email = $validated['email'];

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return back()->with('success', "{$user->name}'s details updated.");
    }

    /**
     * Grant or revoke platform super-admin standing. Guardrails: you can't demote
     * yourself (self-lockout), and the platform can't be left with zero super
     * admins.
     */
    public function updateSuperAdmin(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'is_super_admin' => ['required', 'boolean'],
        ]);

        $makeSuperAdmin = $validated['is_super_admin'];

        if (! $makeSuperAdmin) {
            if ($user->id === $request->user()->id) {
                return back()->with('error', "You can't remove your own super-admin access.");
            }

            if ($this->isLastSuperAdmin($user)) {
                return back()->with('error', "You can't remove the last super admin.");
            }
        }

        $user->is_super_admin = $makeSuperAdmin;
        $user->save();

        return back()->with('success', $makeSuperAdmin
            ? "{$user->name} is now a super admin."
            : "{$user->name} is no longer a super admin.");
    }

    /**
     * Strip a person's admin standing entirely: unset super-admin and detach
     * them from every restaurant. The user record itself is kept (they may still
     * be a customer). Guardrails match the super-admin toggle.
     */
    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->with('error', "You can't remove your own admin access.");
        }

        if ($this->isLastSuperAdmin($user)) {
            return back()->with('error', "You can't remove the last super admin.");
        }

        $name = $user->name;
        $user->is_super_admin = false;
        $user->save();
        $user->restaurants()->detach();

        return back()->with('success', "{$name} is no longer an admin.");
    }

    /**
     * True when this user is a super admin and the only one left, so removing
     * their standing would lock everyone out of the platform console.
     */
    private function isLastSuperAdmin(User $user): bool
    {
        return $user->is_super_admin
            && User::where('is_super_admin', true)->count() <= 1;
    }
}

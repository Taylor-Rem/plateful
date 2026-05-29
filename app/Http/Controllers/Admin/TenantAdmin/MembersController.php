<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\PendingInvitationData;
use App\Data\RestaurantData;
use App\Data\RestaurantMemberData;
use App\Enums\RestaurantRole;
use App\Http\Controllers\Controller;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use App\Models\User;
use App\Policies\MemberPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MembersController extends Controller
{
    public function index(Restaurant $restaurant): Response
    {
        $members = $restaurant->members()
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => RestaurantMemberData::fromModel($user))
            ->all();

        $pendingInvitations = AdminInvitation::query()
            ->where('restaurant_id', $restaurant->id)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->with('invitedBy:id,name')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AdminInvitation $i) => PendingInvitationData::fromModel($i))
            ->all();

        return Inertia::render('Admin/TenantAdmin/Members', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'members' => $members,
            'pendingInvitations' => $pendingInvitations,
            'roles' => collect(RestaurantRole::cases())
                ->map(fn (RestaurantRole $role) => ['value' => $role->value, 'label' => $role->label()])
                ->all(),
        ]);
    }

    public function update(Request $request, Restaurant $restaurant, User $member): RedirectResponse
    {
        $data = $request->validate([
            'role' => ['required', Rule::enum(RestaurantRole::class)],
        ]);

        $pivot = $restaurant->members()->where('users.id', $member->id)->first();

        if (! $pivot) {
            abort(404);
        }

        if ($member->id === $request->user()->id && $data['role'] !== RestaurantRole::Admin->value) {
            throw ValidationException::withMessages([
                'role' => 'You cannot demote yourself.',
            ]);
        }

        $restaurant->members()->updateExistingPivot($member->id, ['role' => $data['role']]);

        return back()->with('success', "{$member->name}'s role updated.");
    }

    public function destroy(Request $request, Restaurant $restaurant, User $member): RedirectResponse
    {
        // Policy is invoked directly rather than via Gate because both actor
        // and target are App\Models\User — Laravel's auto-discovery would
        // route to a (non-existent) UserPolicy, never MemberPolicy.
        if (! (new MemberPolicy)->delete($request->user(), $member)) {
            throw ValidationException::withMessages([
                'member' => 'You cannot remove yourself.',
            ]);
        }

        if (! $restaurant->members()->where('users.id', $member->id)->exists()) {
            abort(404);
        }

        $restaurant->members()->detach($member->id);

        return back()->with('success', "{$member->name} removed from team.");
    }
}

<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Enums\RestaurantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TenantAdmin\StoreInvitationRequest;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use App\Services\AdminInvitationService;
use Illuminate\Http\RedirectResponse;

class InvitationController extends Controller
{
    public function store(StoreInvitationRequest $request, Restaurant $restaurant, AdminInvitationService $invitations): RedirectResponse
    {
        $data = $request->validated();

        $invitation = $invitations->send(
            email: $data['email'],
            restaurant: $restaurant,
            role: RestaurantRole::from($data['role']),
            asSuperAdmin: false,
            invitedBy: $request->user(),
        );

        return back()->with('success', "Invitation sent to {$invitation->email}.");
    }

    public function destroy(Restaurant $restaurant, AdminInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->restaurant_id !== $restaurant->id, 404);

        $invitation->delete();

        return back()->with('success', 'Invitation revoked.');
    }
}

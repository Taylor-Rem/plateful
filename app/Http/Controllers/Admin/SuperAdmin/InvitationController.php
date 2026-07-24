<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SuperAdmin\StorePlatformInvitationRequest;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use App\Services\AdminInvitationService;
use Illuminate\Http\RedirectResponse;

class InvitationController extends Controller
{
    public function store(StorePlatformInvitationRequest $request, AdminInvitationService $invitations): RedirectResponse
    {
        $data = $request->validated();

        $invitation = $invitations->send(
            email: $data['email'],
            restaurant: isset($data['restaurant_id']) && $data['restaurant_id']
                ? Restaurant::findOrFail($data['restaurant_id'])
                : null,
            role: null,
            asSuperAdmin: (bool) ($data['as_super_admin'] ?? false),
            invitedBy: $request->user(),
        );

        return back()->with('success', "Invitation sent to {$invitation->email}.");
    }

    /**
     * Super admins may revoke any pending invitation, platform-level or
     * restaurant-scoped.
     */
    public function destroy(AdminInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->accepted_at !== null, 404);

        $invitation->delete();

        return back()->with('success', 'Invitation revoked.');
    }
}

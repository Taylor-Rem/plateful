<?php

namespace App\Services;

use App\Enums\RestaurantRole;
use App\Mail\AdminInvitationMail;
use App\Models\AdminInvitation;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The single path for creating, mailing, and accepting admin invitations —
 * platform-level (super admin), restaurant-scoped, and the owner invitation
 * sent when a super admin creates a restaurant.
 */
class AdminInvitationService
{
    public function send(
        string $email,
        ?Restaurant $restaurant,
        ?RestaurantRole $role,
        bool $asSuperAdmin,
        User $invitedBy,
    ): AdminInvitation {
        $invitation = AdminInvitation::create([
            'email' => $email,
            'restaurant_id' => $restaurant?->id,
            // The column is NOT NULL with an 'admin' default; Admin also
            // matches accept()'s fallback when no role was chosen.
            'role' => $role ?? RestaurantRole::Admin,
            'as_super_admin' => $asSuperAdmin,
            'token' => AdminInvitation::generateToken(),
            'invited_by_user_id' => $invitedBy->id,
            'expires_at' => now()->addDays(7),
        ]);

        Mail::to($invitation->email)->queue(new AdminInvitationMail($invitation));

        return $invitation;
    }

    public function accept(AdminInvitation $invitation, string $name, string $password): User
    {
        return DB::transaction(function () use ($invitation, $name, $password) {
            $user = User::create([
                'name' => $name,
                'email' => $invitation->email,
                'password' => $password,
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
    }
}

<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Match, or create, the local user for a verified social identity. Shared by
 * the web Google redirect flow and the API's ID-token endpoints so web and
 * app customers are always one account.
 */
class SocialAccountResolver
{
    /**
     * Order: (1) an existing account already linked to this provider id, then
     * (2) an existing account with the same email — but only auto-link by
     * email when the provider reports it verified. Returns null when the
     * email is taken but unverified (blocks takeover of an account via an
     * unverified social email), or when the provider gave no email at all.
     */
    public function resolve(SocialIdentity $identity): ?User
    {
        $column = $identity->provider->identifierColumn();

        $existingByProviderId = User::query()->where($column, $identity->id)->first();

        if ($existingByProviderId !== null) {
            return $existingByProviderId;
        }

        if ($identity->email === null) {
            return null;
        }

        $existingByEmail = User::query()->where('email', $identity->email)->first();

        if ($existingByEmail !== null) {
            if (! $identity->emailVerified) {
                return null;
            }

            $existingByEmail->forceFill(array_filter([
                $column => $identity->id,
                'avatar' => $identity->avatar,
            ], fn ($value) => $value !== null))->save();

            return $existingByEmail;
        }

        return tap(new User, function (User $user) use ($identity, $column): void {
            $user->forceFill([
                'name' => $identity->name ?: $identity->email,
                'email' => $identity->email,
                $column => $identity->id,
                'avatar' => $identity->avatar,
                'password' => Str::password(32),
                'email_verified_at' => $identity->emailVerified ? now() : null,
            ])->save();
        });
    }
}

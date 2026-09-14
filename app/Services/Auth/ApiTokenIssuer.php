<?php

namespace App\Services\Auth;

use App\Models\User;
use Laravel\Fortify\Features;

/**
 * Issues Sanctum bearer tokens for the mobile app: one token per device
 * (re-signing in on a device replaces its token), and a short-lived
 * challenge token that can only complete a two-factor login.
 */
class ApiTokenIssuer
{
    public const ABILITY_CUSTOMER = 'customer';

    public const ABILITY_TWO_FACTOR_CHALLENGE = 'two-factor-challenge';

    /**
     * Granted alongside `customer` to anyone with admin standing (super admin
     * or a restaurant pivot row): it unlocks /api/v1/operator/*.
     */
    public const ABILITY_OPERATOR = 'operator';

    protected const CHALLENGE_TTL_MINUTES = 5;

    /**
     * Issue the device's customer token, revoking any earlier token for the
     * same device name. Returns the plain-text bearer token.
     */
    public function issue(User $user, string $deviceName): string
    {
        $user->tokens()->where('name', $deviceName)->delete();

        return $user->createToken($deviceName, $this->abilitiesFor($user))->plainTextToken;
    }

    /**
     * Issue a token that can only be used to answer the two-factor challenge.
     * Re-attempting login on the same device replaces the outstanding one.
     */
    public function issueTwoFactorChallenge(User $user, string $deviceName): string
    {
        $name = $this->challengeTokenName($deviceName);

        $user->tokens()->where('name', $name)->delete();

        return $user->createToken(
            $name,
            [self::ABILITY_TWO_FACTOR_CHALLENGE],
            now()->addMinutes(static::CHALLENGE_TTL_MINUTES),
        )->plainTextToken;
    }

    /**
     * The device name the challenge token was issued for, so the customer
     * token that completes the login lands under the same device.
     */
    public function deviceNameFromChallenge(string $challengeTokenName): string
    {
        return str_starts_with($challengeTokenName, 'two-factor:')
            ? substr($challengeTokenName, strlen('two-factor:'))
            : $challengeTokenName;
    }

    /**
     * Mirrors the web login pipeline: the challenge runs wherever Fortify's
     * feature is on and the user has confirmed enrollment, except in local
     * development, where FortifyServiceProvider drops the challenge step too.
     */
    public function requiresTwoFactorChallenge(User $user): bool
    {
        if (app()->environment('local')) {
            return false;
        }

        if (! Features::enabled(Features::twoFactorAuthentication())) {
            return false;
        }

        return $user->hasEnabledTwoFactorAuthentication();
    }

    /**
     * @return array<int, string>
     */
    public function abilitiesFor(User $user): array
    {
        return $user->isAdmin()
            ? [self::ABILITY_CUSTOMER, self::ABILITY_OPERATOR]
            : [self::ABILITY_CUSTOMER];
    }

    protected function challengeTokenName(string $deviceName): string
    {
        return 'two-factor:'.$deviceName;
    }
}

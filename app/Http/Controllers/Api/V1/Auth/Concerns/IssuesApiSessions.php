<?php

namespace App\Http\Controllers\Api\V1\Auth\Concerns;

use App\Data\AuthSessionData;
use App\Data\MeData;
use App\Data\TwoFactorChallengeData;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use App\Services\CartManager;
use Illuminate\Http\JsonResponse;

/**
 * The tail of every API sign-in: either a device token (200) or, for an
 * account with two-factor enrolled, a challenge token (202) that the app
 * must exchange at POST /auth/two-factor.
 */
trait IssuesApiSessions
{
    protected function startSession(User $user, string $deviceName): JsonResponse
    {
        $issuer = app(ApiTokenIssuer::class);

        if ($issuer->requiresTwoFactorChallenge($user)) {
            return response()->json([
                'data' => new TwoFactorChallengeData(
                    twoFactorRequired: true,
                    challengeToken: $issuer->issueTwoFactorChallenge($user, $deviceName),
                ),
            ], 202);
        }

        return $this->sessionResponse($user, $issuer->issue($user, $deviceName));
    }

    protected function sessionResponse(User $user, string $token): JsonResponse
    {
        // A guest who built a cart and then signed in keeps it (web parity).
        app(CartManager::class)->mergeHeaderCartIntoUser($user);

        return response()->json([
            'data' => new AuthSessionData(
                token: $token,
                user: MeData::fromModel($user),
            ),
        ]);
    }
}

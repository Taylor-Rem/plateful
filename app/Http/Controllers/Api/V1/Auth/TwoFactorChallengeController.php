<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\Auth\Concerns\IssuesApiSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\TwoFactorChallengeRequest;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Completes a sign-in that paused at the two-factor step. Authenticated by
 * the short-lived challenge token; a valid TOTP or recovery code swaps it for
 * the device's real customer token.
 */
class TwoFactorChallengeController extends Controller
{
    use IssuesApiSessions;

    public function store(
        TwoFactorChallengeRequest $request,
        TwoFactorAuthenticationProvider $provider,
        ApiTokenIssuer $issuer,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        if (! $this->codeIsValid($user, $request, $provider)) {
            throw ValidationException::withMessages([
                'code' => __('The provided two factor authentication code was invalid.'),
            ]);
        }

        $challengeToken = $user->currentAccessToken();
        $deviceName = $issuer->deviceNameFromChallenge((string) $challengeToken->name);
        $challengeToken->delete();

        return $this->sessionResponse($user, $issuer->issue($user, $deviceName));
    }

    private function codeIsValid(User $user, TwoFactorChallengeRequest $request, TwoFactorAuthenticationProvider $provider): bool
    {
        if (($code = $request->code()) !== null) {
            return $provider->verify(decrypt($user->two_factor_secret), $code);
        }

        $recoveryCode = $request->recoveryCode();

        $match = collect($user->recoveryCodes())
            ->first(fn (string $candidate): bool => hash_equals($candidate, (string) $recoveryCode));

        if ($match === null) {
            return false;
        }

        $user->replaceRecoveryCode($match);

        return true;
    }
}

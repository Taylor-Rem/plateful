<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Api\V1\Auth\Concerns\IssuesApiSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\SocialLoginRequest;
use App\Services\Auth\AppleIdTokenVerifier;
use App\Services\Auth\GoogleIdTokenVerifier;
use App\Services\Auth\InvalidIdTokenException;
use App\Services\Auth\SocialAccountResolver;
use App\Services\Auth\SocialIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * Native social sign-in for the mobile app. The app completes the provider's
 * own flow on-device and posts the resulting ID token; we verify it against
 * the provider's published keys and resolve the same User the web flow would.
 */
class SocialLoginController extends Controller
{
    use IssuesApiSessions;

    public function __construct(private readonly SocialAccountResolver $resolver) {}

    public function google(SocialLoginRequest $request, GoogleIdTokenVerifier $verifier): JsonResponse
    {
        abort_unless($verifier->isConfigured(), 503, 'Google sign-in is not available.');

        $identity = $this->verify(fn (): SocialIdentity => $verifier->verify($request->idToken()));

        return $this->signIn($identity, $request->deviceName());
    }

    public function apple(SocialLoginRequest $request, AppleIdTokenVerifier $verifier): JsonResponse
    {
        abort_unless($verifier->isConfigured(), 503, 'Sign in with Apple is not available.');

        $identity = $this->verify(fn (): SocialIdentity => $verifier->verify($request->idToken(), $request->name()));

        return $this->signIn($identity, $request->deviceName());
    }

    /**
     * @param  callable(): SocialIdentity  $verify
     */
    private function verify(callable $verify): SocialIdentity
    {
        try {
            return $verify();
        } catch (InvalidIdTokenException $e) {
            throw ValidationException::withMessages(['id_token' => $e->getMessage()]);
        }
    }

    private function signIn(SocialIdentity $identity, string $deviceName): JsonResponse
    {
        $user = $this->resolver->resolve($identity);

        if ($user === null) {
            // The email belongs to an existing account the provider has not
            // verified, or the provider sent no email: refuse rather than
            // link, exactly as the web Google callback does.
            throw ValidationException::withMessages([
                'id_token' => __('We could not sign you in with that account. Please sign in with your email and password.'),
            ]);
        }

        return $this->startSession($user, $deviceName);
    }
}

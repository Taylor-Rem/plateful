<?php

namespace App\Services\Delivery\UberDirect;

use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Mints platform-level Uber Direct access tokens via the `client_credentials`
 * grant. Umbrella model: ONE credential set (config `services.uber_direct`)
 * authenticates every restaurant's deliveries, so there is exactly one live
 * token per scope for the whole platform.
 *
 * Tokens are cached because they live 30 days and Uber rate-limits the grant
 * to 100 requests per hour: re-minting per request would break every
 * integration under any real load, so caching is a correctness requirement
 * here, not an optimization.
 *
 * IMPORTANT: a token only authorizes the sub-organizations that existed when
 * it was minted (verified against the live sandbox — a pre-provisioning token
 * gets 403 for a newly created org). UberDirectProvisioningService calls
 * `forget()` after creating an org so the next delivery call re-mints.
 */
class UberDirectTokenService
{
    public const TOKEN_URL = 'https://auth.uber.com/oauth/v2/token';

    public const SCOPE_DELIVERIES = 'eats.deliveries';

    public const SCOPE_ORGANIZATIONS = 'direct.organizations';

    /**
     * Uber's documented token lifetime (30 days), used only if a response
     * omits `expires_in`.
     */
    public const DOCUMENTED_LIFETIME_SECONDS = 2_592_000;

    /**
     * Re-mint this long before expiry. Generous because the token lives 30 days
     * and the grant is cheap at that cadence — but far enough inside the
     * 100/hour limit that a fleet of workers can't stampede it.
     */
    private const REFRESH_WINDOW_HOURS = 24;

    /**
     * A usable platform access token for the given scope, minting one if the
     * cached token is missing or near expiry.
     */
    public function freshAccessToken(string $scope = self::SCOPE_DELIVERIES): string
    {
        $cached = Cache::get($this->cacheKey($scope));

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $token = $this->requestToken($scope);

        // The cache entry dies REFRESH_WINDOW_HOURS before the token does, so a
        // cache hit is always a token with comfortable life left.
        $cacheUntil = $token->expiresAt->subHours(self::REFRESH_WINDOW_HOURS);
        if ($cacheUntil->isFuture()) {
            Cache::put($this->cacheKey($scope), $token->accessToken, $cacheUntil);
        }

        return $token->accessToken;
    }

    /**
     * Drop the cached tokens so the next call re-mints. Required after
     * provisioning a new sub-organization: existing tokens do not authorize
     * orgs created after they were minted.
     */
    public function forget(): void
    {
        Cache::forget($this->cacheKey(self::SCOPE_DELIVERIES));
        Cache::forget($this->cacheKey(self::SCOPE_ORGANIZATIONS));
    }

    /**
     * Run the grant against the platform credentials without touching the
     * cache. Failures surface as DeliveryProviderException with an ops-facing
     * reason (a platform credential problem is Plateful's to fix, not a
     * restaurant's).
     */
    public function requestToken(string $scope = self::SCOPE_DELIVERIES): UberDirectToken
    {
        $clientId = (string) config('services.uber_direct.client_id');
        $clientSecret = (string) config('services.uber_direct.client_secret');

        if ($clientId === '' || $clientSecret === '') {
            throw DeliveryProviderException::notConfigured(DeliveryProviderName::Uber->value);
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post(self::TOKEN_URL, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
                'scope' => $scope,
            ]);

        if ($response->failed()) {
            throw DeliveryProviderException::authenticationFailed(
                DeliveryProviderName::Uber->value,
                $this->describeFailure($response->status(), (array) $response->json(), $scope),
            );
        }

        $payload = (array) $response->json();

        if (! isset($payload['access_token']) || ! is_string($payload['access_token']) || $payload['access_token'] === '') {
            throw DeliveryProviderException::authenticationFailed(
                DeliveryProviderName::Uber->value,
                'Uber returned no access token.',
            );
        }

        return UberDirectToken::fromResponse($payload);
    }

    private function cacheKey(string $scope): string
    {
        return 'uber_direct.platform_token.'.$scope;
    }

    /**
     * Turn Uber's error body into something actionable in logs/last_error.
     *
     * The mapping below was verified against the live sandbox rather than taken
     * from the docs — Uber distinguishes an unknown client id (401
     * `invalid_client`) from a bad secret (403 `access_denied`), which lets us
     * name the credential that's actually wrong instead of blaming both.
     *
     * @param  array<string, mixed>  $body
     */
    private function describeFailure(int $status, array $body, string $scope): string
    {
        $error = is_string($body['error'] ?? null) ? $body['error'] : null;

        return match ($error) {
            'invalid_client' => 'Uber does not recognize the platform Client ID.',
            'access_denied' => 'Uber rejected the platform Client Secret.',
            // Not a credential problem: the account itself has not been granted
            // this scope, so no credential from it can ever mint a token for it.
            'invalid_scope' => "The platform Uber account is not enabled for the {$scope} scope."
                .' Finish account setup at direct.uber.com and accept the API Terms of Use, then try again.',
            null => "Uber returned HTTP {$status}.",
            default => "Uber returned HTTP {$status}: {$error}",
        };
    }
}

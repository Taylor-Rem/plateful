<?php

namespace App\Services\Delivery\UberDirect;

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryIntegration;
use Illuminate\Support\Facades\Http;

/**
 * Mints and stores Uber Direct access tokens via the `client_credentials`
 * grant. Simpler than the Square/Clover OAuth services — machine-to-machine,
 * no redirect, no callback route, no refresh-token rotation.
 *
 * Tokens are stored on the integration rather than a cache because they live 30
 * days and Uber rate-limits the grant to 100 requests per hour: re-minting per
 * request would break the integration under any real load, so caching is a
 * correctness requirement here, not an optimization.
 */
class UberDirectTokenService
{
    public const TOKEN_URL = 'https://auth.uber.com/oauth/v2/token';

    public const SCOPE = 'eats.deliveries';

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
     * A usable access token for this restaurant's Uber account, minting one if
     * the stored token is missing or near expiry.
     */
    public function freshAccessToken(DeliveryIntegration $integration): string
    {
        if (! $integration->hasCredentials()) {
            throw DeliveryProviderException::notConfigured(DeliveryProviderName::Uber->value);
        }

        $expiresAt = $integration->token_expires_at;

        if ($integration->access_token !== null
            && $expiresAt !== null
            && $expiresAt->isAfter(now()->addHours(self::REFRESH_WINDOW_HOURS))) {
            return (string) $integration->access_token;
        }

        return $this->mint($integration);
    }

    /**
     * Run the grant and persist the result onto the integration, flipping it to
     * `error` (with the reason) if Uber rejects the credentials.
     */
    public function mint(DeliveryIntegration $integration): string
    {
        try {
            $token = $this->requestToken(
                (string) $integration->client_id,
                (string) $integration->client_secret,
            );
        } catch (DeliveryProviderException $e) {
            // Park the reason where the owner can see it. Clearing the token
            // means the next attempt re-mints rather than using a stale one.
            $integration->forceFill([
                'status' => DeliveryIntegrationStatus::Error,
                'last_error' => $e->getMessage(),
                'access_token' => null,
                'token_expires_at' => null,
            ])->save();

            throw $e;
        }

        $integration->forceFill([
            'access_token' => $token->accessToken,
            'token_expires_at' => $token->expiresAt,
            'status' => DeliveryIntegrationStatus::Connected,
            'last_error' => null,
        ])->save();

        return $token->accessToken;
    }

    /**
     * Exchange raw credentials for a token without touching the database. Lets
     * the admin credential form prove pasted values actually work before they
     * are saved.
     */
    public function requestToken(string $clientId, string $clientSecret): UberDirectToken
    {
        $response = Http::asForm()
            ->timeout(15)
            ->post(self::TOKEN_URL, [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'grant_type' => 'client_credentials',
                'scope' => self::SCOPE,
            ]);

        if ($response->failed()) {
            throw DeliveryProviderException::authenticationFailed(
                DeliveryProviderName::Uber->value,
                $this->describeFailure($response->status(), (array) $response->json()),
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

    /**
     * Turn Uber's error body into something an owner can act on.
     *
     * The mapping below was verified against the live sandbox rather than taken
     * from the docs — Uber distinguishes an unknown client id (401
     * `invalid_client`) from a bad secret (403 `access_denied`), which lets us
     * name the field that's actually wrong instead of blaming both.
     *
     * @param  array<string, mixed>  $body
     */
    private function describeFailure(int $status, array $body): string
    {
        $error = is_string($body['error'] ?? null) ? $body['error'] : null;

        return match ($error) {
            'invalid_client' => 'Uber does not recognize this Client ID.',
            'access_denied' => 'Uber rejected the Client Secret.',
            // Not a credential problem: the account itself has not been granted
            // Direct API access, so no credential from it can ever mint a
            // delivery-scoped token.
            'invalid_scope' => 'This Uber account is not enabled for the '.self::SCOPE
                .' scope. Finish account setup at direct.uber.com and accept the API Terms of Use, then try again.',
            null => "Uber returned HTTP {$status}.",
            default => "Uber returned HTTP {$status}: {$error}",
        };
    }
}

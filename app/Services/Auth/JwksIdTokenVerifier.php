<?php

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * Verifies an OpenID Connect ID token against a provider's published JWKS:
 * signature, expiry, issuer, and audience. Providers subclass this with their
 * JWKS URL, accepted issuers, and claim mapping.
 */
abstract class JwksIdTokenVerifier
{
    protected const JWKS_CACHE_TTL_SECONDS = 3600;

    protected const CLOCK_LEEWAY_SECONDS = 60;

    abstract protected function jwksUrl(): string;

    /**
     * @return array<int, string>
     */
    abstract protected function issuers(): array;

    /**
     * Audiences (client ids) this deployment accepts. Empty means the provider
     * is not configured and every token is rejected.
     *
     * @return array<int, string>
     */
    abstract public function audiences(): array;

    public function isConfigured(): bool
    {
        return $this->audiences() !== [];
    }

    /**
     * Decode and validate the token, returning its claims.
     *
     * @throws InvalidIdTokenException
     */
    protected function claims(string $idToken): stdClass
    {
        if (! $this->isConfigured()) {
            throw new InvalidIdTokenException('Sign-in provider is not configured.');
        }

        try {
            JWT::$leeway = static::CLOCK_LEEWAY_SECONDS;
            $claims = JWT::decode($idToken, JWK::parseKeySet($this->keySet(), 'RS256'));
        } catch (InvalidIdTokenException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new InvalidIdTokenException('The sign-in token could not be verified.', previous: $e);
        }

        if (! in_array($claims->iss ?? null, $this->issuers(), true)) {
            throw new InvalidIdTokenException('The sign-in token was not issued by the expected provider.');
        }

        $audience = (array) ($claims->aud ?? []);

        if (array_intersect($audience, $this->audiences()) === []) {
            throw new InvalidIdTokenException('The sign-in token was not issued for this app.');
        }

        if (! isset($claims->sub) || ! is_string($claims->sub) || $claims->sub === '') {
            throw new InvalidIdTokenException('The sign-in token has no subject.');
        }

        return $claims;
    }

    /**
     * @return array{keys: array<int, array<string, mixed>>}
     */
    protected function keySet(): array
    {
        $cacheKey = 'jwks:'.static::class;

        $keySet = Cache::remember($cacheKey, static::JWKS_CACHE_TTL_SECONDS, function (): array {
            $response = Http::timeout(10)->acceptJson()->get($this->jwksUrl());

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new InvalidIdTokenException('The sign-in provider keys could not be fetched.');
            }

            return ['keys' => $response->json('keys')];
        });

        return $keySet;
    }

    /**
     * Providers report email verification as a bool or, in Apple's case,
     * sometimes as the string "true".
     */
    protected function emailVerifiedClaim(stdClass $claims): bool
    {
        $value = $claims->email_verified ?? false;

        return $value === true || $value === 'true';
    }
}

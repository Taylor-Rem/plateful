<?php

use App\Models\User;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

const API_BASE = 'http://plateful.test/api/v1';

/**
 * A test-only RSA keypair whose public half is served as the provider's JWKS
 * and whose private half signs the ID tokens under test. Memoised per process
 * so the JWKS cache (1h, array store) stays consistent across tests.
 *
 * @return array{private: OpenSSLAsymmetricKey, jwk: array<string, string>}
 */
function apiTestKeypair(): array
{
    static $pair = null;

    if ($pair === null) {
        $private = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $details = openssl_pkey_get_details($private);
        $b64url = fn (string $bin): string => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

        $pair = [
            'private' => $private,
            'jwk' => [
                'kty' => 'RSA',
                'alg' => 'RS256',
                'use' => 'sig',
                'kid' => 'test-key-1',
                'n' => $b64url($details['rsa']['n']),
                'e' => $b64url($details['rsa']['e']),
            ],
        ];
    }

    return $pair;
}

/**
 * Serve the test JWKS for both providers (and refuse anything else).
 */
function fakeProviderJwks(): void
{
    $jwks = ['keys' => [apiTestKeypair()['jwk']]];

    Http::fake([
        'https://www.googleapis.com/oauth2/v3/certs' => Http::response($jwks),
        'https://appleid.apple.com/auth/keys' => Http::response($jwks),
    ]);
}

/**
 * @param  array<string, mixed>  $claims
 */
function signIdToken(array $claims, string $kid = 'test-key-1'): string
{
    return JWT::encode($claims, apiTestKeypair()['private'], 'RS256', $kid);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function googleIdToken(array $overrides = []): string
{
    return signIdToken([
        'iss' => 'https://accounts.google.com',
        'aud' => 'ios-client-id.apps.googleusercontent.com',
        'sub' => 'google-sub-123',
        'email' => 'ada@example.com',
        'email_verified' => true,
        'name' => 'Ada Diner',
        'picture' => 'https://avatars.test/ada.png',
        'iat' => time(),
        'exp' => time() + 3600,
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function appleIdToken(array $overrides = []): string
{
    return signIdToken([
        'iss' => 'https://appleid.apple.com',
        'aud' => 'fyi.plateful.app',
        'sub' => 'apple-sub-001.abc',
        'email' => 'ada@privaterelay.appleid.com',
        'email_verified' => 'true',
        'iat' => time(),
        'exp' => time() + 3600,
        ...$overrides,
    ]);
}

function apiTokenFor(User $user, string $device = 'iPhone'): string
{
    return $user->createToken($device, ['customer'])->plainTextToken;
}

/**
 * The auth manager memoises the resolved user per guard for the life of the
 * app instance, so a second request in the same test would still see a token
 * that has since been revoked. Call between requests that must re-resolve.
 */
function forgetApiGuards(): void
{
    app('auth')->forgetGuards();
    // withToken()/withHeader() persist as default headers for the whole
    // test; drop them too so the next request starts as a stranger.
    test()->flushHeaders();
}

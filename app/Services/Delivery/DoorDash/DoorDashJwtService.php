<?php

namespace App\Services\Delivery\DoorDash;

use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use App\Services\Delivery\UberDirect\UberDirectTokenService;

/**
 * Mints DoorDash's DD-JWT-V1 access tokens on demand.
 *
 * DoorDash Drive does not use OAuth. Every request carries a self-signed JWT
 * (HS256) minted from the platform's `developer_id` / `key_id` / `signing_secret`
 * — one credential set for the whole platform, not per restaurant. Because the
 * tokens are cheap to mint and live only minutes, there is nothing to cache or
 * persist the way {@see UberDirectTokenService}
 * must: this class just signs a fresh one each call.
 *
 * Two DoorDash-specific details make or break the signature:
 *   1. The header carries a `dd-ver: DD-JWT-V1` claim alongside the usual JWT
 *      fields — DoorDash rejects a standard JWT without it.
 *   2. The signing secret is itself base64url-encoded; it must be DECODED to its
 *      raw bytes before it is used as the HMAC key. Signing against the encoded
 *      string produces a token that verifies locally but fails at DoorDash.
 */
class DoorDashJwtService
{
    /**
     * Token lifetime in seconds. DoorDash accepts up to 30 minutes; we mint
     * short-lived tokens because there is no reuse — one per request.
     */
    public const LIFETIME_SECONDS = 300;

    /**
     * A signed DD-JWT-V1 compact token, or a not-configured exception if the
     * platform credentials are missing.
     */
    public function mint(): string
    {
        $developerId = (string) config('services.doordash.developer_id');
        $keyId = (string) config('services.doordash.key_id');
        $signingSecret = (string) config('services.doordash.signing_secret');

        if ($developerId === '' || $keyId === '' || $signingSecret === '') {
            throw DeliveryProviderException::notConfigured(DeliveryProviderName::DoorDash->value);
        }

        $header = [
            'alg' => 'HS256',
            'dd-ver' => 'DD-JWT-V1',
            'typ' => 'JWT',
            'kid' => $keyId,
        ];

        $issuedAt = now()->timestamp;
        $payload = [
            'aud' => 'doordash',
            'iss' => $developerId,
            'kid' => $keyId,
            'exp' => $issuedAt + self::LIFETIME_SECONDS,
            'iat' => $issuedAt,
        ];

        $segments = [
            self::base64UrlEncode(self::json($header)),
            self::base64UrlEncode(self::json($payload)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, self::base64UrlDecode($signingSecret), true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return (string) base64_decode(strtr($data, '-_', '+/'), true);
    }
}

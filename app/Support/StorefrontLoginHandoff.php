<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Cross-subdomain login handoff.
 *
 * Sessions are intentionally scoped per host (SESSION_DOMAIN is null), so a
 * login established on the platform host cannot be shared with a storefront
 * subdomain. After Google authenticates a customer on the platform host, we
 * mint a short-lived, single-use, host-bound token that the storefront
 * exchanges for a session of its own. The token is encrypted with the app key,
 * so a malicious tenant cannot forge one.
 */
class StorefrontLoginHandoff
{
    private const TTL_SECONDS = 120;

    private const CACHE_PREFIX = 'google-login-handoff:';

    /**
     * Issue a token authenticating $user, valid only on $host.
     */
    public function issue(User $user, string $host): string
    {
        return Crypt::encryptString((string) json_encode([
            'uid' => $user->id,
            'host' => $host,
            'jti' => (string) Str::uuid(),
            'exp' => now()->addSeconds(self::TTL_SECONDS)->getTimestamp(),
        ]));
    }

    /**
     * Consume a token on $host. Returns the user, or null when the token is
     * invalid, expired, replayed, or minted for a different host.
     */
    public function consume(string $token, string $host): ?User
    {
        if ($token === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($token), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $uid = $payload['uid'] ?? null;
        $jti = $payload['jti'] ?? null;

        if (! $uid || ! is_string($jti) || ($payload['host'] ?? null) !== $host) {
            return null;
        }

        if (now()->getTimestamp() > (int) ($payload['exp'] ?? 0)) {
            return null;
        }

        // Single-use: the first consume claims the jti; a replay finds it
        // already present and is rejected.
        if (! Cache::add(self::CACHE_PREFIX.$jti, true, self::TTL_SECONDS)) {
            return null;
        }

        return User::find($uid);
    }
}

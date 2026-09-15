<?php

namespace App\Support\Api;

use App\Models\ApiCallLog;
use App\Models\ApiKey;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Writes the audit trail behind the AI assistant page. Every MCP tool call
 * (PlatformServer) and operator REST request (LogOperatorApiCall) lands
 * here; a logging failure is reported, never surfaced to the caller.
 */
final class ApiCallLogger
{
    /**
     * Argument values longer than this are replaced by a size note, and the
     * image payload keys are dropped outright: the log is for "what did the
     * assistant do", not for replaying uploads.
     */
    private const MAX_VALUE_LENGTH = 200;

    private const OMITTED_KEYS = ['image_base64', 'source_url'];

    /**
     * @param  array<string, mixed>|null  $arguments
     */
    public function record(
        string $channel,
        string $action,
        ?Authenticatable $principal,
        ?Restaurant $restaurant,
        ?array $arguments,
        bool $ok,
        ?string $error,
        int $durationMs,
    ): ?ApiCallLog {
        try {
            return ApiCallLog::query()->create([
                'api_key_id' => $principal instanceof ApiKey ? $principal->id : null,
                'user_id' => $principal instanceof User ? $principal->id : null,
                'restaurant_id' => $restaurant?->id,
                'channel' => $channel,
                'action' => mb_substr($action, 0, 80),
                'arguments' => $arguments !== null ? self::redact($arguments) : null,
                'ok' => $ok,
                'error' => $error !== null ? mb_substr($error, 0, 500) : null,
                'duration_ms' => max(0, $durationMs),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The restaurant a call should be filed under when the tool did not get
     * as far as resolving one: a restaurant key's own restaurant, or the
     * subdomain the caller named.
     */
    public static function restaurantFor(?Authenticatable $principal, mixed $subdomain): ?Restaurant
    {
        if ($principal instanceof ApiKey && ! $principal->isPlatform()) {
            return $principal->restaurant;
        }

        if (is_string($subdomain) && $subdomain !== '') {
            return Restaurant::query()->where('subdomain', $subdomain)->first();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public static function redact(array $arguments): array
    {
        $clean = [];

        foreach ($arguments as $key => $value) {
            if (in_array($key, self::OMITTED_KEYS, true)) {
                $clean[$key] = is_string($value) ? '[omitted, '.strlen($value).' bytes]' : '[omitted]';

                continue;
            }

            if (is_string($value) && mb_strlen($value) > self::MAX_VALUE_LENGTH) {
                $clean[$key] = mb_substr($value, 0, self::MAX_VALUE_LENGTH).'… ['.mb_strlen($value).' chars]';

                continue;
            }

            $clean[$key] = is_array($value) ? self::redact($value) : $value;
        }

        return $clean;
    }
}

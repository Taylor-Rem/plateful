<?php

namespace App\Data;

use App\Models\ApiCallLog;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A row of the AI assistant page's "Recent activity" list.
 */
#[TypeScript]
class ApiCallLogData extends Data
{
    /**
     * @param  array<string, mixed>|null  $arguments
     */
    public function __construct(
        public int $id,
        public string $channel,
        public string $action,
        public ?string $keyName,
        public ?string $keyPrefix,
        public ?string $userName,
        public ?array $arguments,
        public bool $ok,
        public ?string $error,
        public int $durationMs,
        public string $createdAt,
    ) {}

    public static function fromModel(ApiCallLog $log): self
    {
        return new self(
            id: $log->id,
            channel: $log->channel,
            action: $log->action,
            keyName: $log->apiKey?->name,
            keyPrefix: $log->apiKey?->key_prefix,
            userName: $log->user?->name,
            arguments: $log->arguments,
            ok: $log->ok,
            error: $log->error,
            durationMs: $log->duration_ms,
            createdAt: $log->created_at->toIso8601String(),
        );
    }
}

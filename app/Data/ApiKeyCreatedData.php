<?php

namespace App\Data;

use App\Models\ApiKey;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class ApiKeyCreatedData extends Data
{
    public function __construct(
        public ApiKeyData $key,
        /** Shown once; only its hash is stored. */
        public string $plainTextKey,
    ) {}

    public static function fromMint(ApiKey $key, string $plainTextKey): self
    {
        return new self(
            key: ApiKeyData::fromModel($key),
            plainTextKey: $plainTextKey,
        );
    }
}

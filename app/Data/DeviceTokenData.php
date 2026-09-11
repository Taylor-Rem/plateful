<?php

namespace App\Data;

use App\Models\DeviceToken;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class DeviceTokenData extends Data
{
    public function __construct(
        public string $token,
        public string $platform,
        public ?string $deviceName,
        public ?string $lastSeenAt,
    ) {}

    public static function fromModel(DeviceToken $device): self
    {
        return new self(
            token: $device->token,
            platform: $device->platform,
            deviceName: $device->device_name,
            lastSeenAt: $device->last_seen_at?->toIso8601String(),
        );
    }
}

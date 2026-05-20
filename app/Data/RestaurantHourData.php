<?php

namespace App\Data;

use App\Models\RestaurantHour;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class RestaurantHourData extends Data
{
    public function __construct(
        public int $dayOfWeek,
        public string $opensAt,
        public string $closesAt,
        public int $position,
    ) {}

    public static function fromModel(RestaurantHour $hour): self
    {
        return new self(
            dayOfWeek: (int) $hour->day_of_week,
            opensAt: substr((string) $hour->opens_at, 0, 5),
            closesAt: substr((string) $hour->closes_at, 0, 5),
            position: (int) $hour->position,
        );
    }
}

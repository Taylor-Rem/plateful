<?php

namespace App\Data;

use App\Enums\ApiKeyScope;
use App\Models\Restaurant;
use App\Support\Api\ApiActor;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `GET /operator/me`: who signed the request and what they can reach.
 */
#[TypeScript]
class OperatorActorData extends Data
{
    /**
     * @param  array<int, string>  $scopes
     */
    public function __construct(
        /** `user` or `api_key`. */
        public string $type,
        public string $name,
        public bool $isPlatform,
        /** For keys, the granted scopes; for users, their scopes at each restaurant are on the restaurant rows. */
        public array $scopes,
        #[DataCollectionOf(OperatorRestaurantData::class)]
        /** @var array<int, OperatorRestaurantData> */
        public array $restaurants,
    ) {}

    public static function fromActor(ApiActor $actor): self
    {
        return new self(
            type: $actor->type(),
            name: $actor->name(),
            isPlatform: $actor->isPlatform(),
            scopes: array_map(fn (ApiKeyScope $s) => $s->value, $actor->scopesAt()),
            restaurants: $actor->restaurants()
                ->map(fn (Restaurant $r) => OperatorRestaurantData::fromModel($r, $actor))
                ->values()
                ->all(),
        );
    }
}

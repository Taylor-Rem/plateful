<?php

namespace App\Data;

use App\Models\Address;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
class AddressData extends Data
{
    public function __construct(
        public int $id,
        public ?string $label,
        public string $street,
        public ?string $street2,
        public string $city,
        public string $state,
        public string $postalCode,
        public string $country,
        public ?string $instructions,
        public bool $isDefault,
    ) {}

    public static function fromModel(Address $address): self
    {
        return new self(
            id: $address->id,
            label: $address->label,
            street: $address->street,
            street2: $address->street2,
            city: $address->city,
            state: $address->state,
            postalCode: $address->postal_code,
            country: $address->country,
            instructions: $address->instructions,
            isDefault: (bool) $address->is_default,
        );
    }
}

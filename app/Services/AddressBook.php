<?php

namespace App\Services;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The saved-address rules shared by the storefront account page and the
 * app: one default per user, country falls back to US.
 */
class AddressBook
{
    /**
     * @param  array<string, mixed>  $data  validated Store/UpdateAddressRequest input
     */
    public function store(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data): Address {
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                $user->addresses()->update(['is_default' => false]);
            }

            return $user->addresses()->create($this->attributes($data, $isDefault));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Address $address, array $data): Address
    {
        return DB::transaction(function () use ($address, $data): Address {
            $isDefault = (bool) ($data['is_default'] ?? false);

            if ($isDefault) {
                Address::query()
                    ->where('user_id', $address->user_id)
                    ->whereKeyNot($address->id)
                    ->update(['is_default' => false]);
            }

            $address->update($this->attributes($data, $isDefault));

            return $address;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function attributes(array $data, bool $isDefault): array
    {
        return [
            'label' => $data['label'] ?? null,
            'street' => $data['street'],
            'street2' => $data['street2'] ?? null,
            'city' => $data['city'],
            'state' => $data['state'],
            'postal_code' => $data['postal_code'],
            'country' => ($data['country'] ?? '') ?: 'US',
            'instructions' => $data['instructions'] ?? null,
            'is_default' => $isDefault,
        ];
    }
}

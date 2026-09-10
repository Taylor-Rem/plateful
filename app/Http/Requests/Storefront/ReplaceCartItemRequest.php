<?php

namespace App\Http\Requests\Storefront;

/**
 * Same payload as adding a line — the whole configuration is resubmitted —
 * but the quantity is explicit because it replaces, never stacks.
 */
class ReplaceCartItemRequest extends AddCartItemRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'quantity' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }
}

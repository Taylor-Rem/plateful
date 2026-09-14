<?php

namespace App\Http\Requests\Api\V1\Operator;

use App\Enums\ApiKeyScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApiKeyStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Restaurant keys may hold any restaurant scope; the platform wildcard
     * is minted from the console only.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['string', Rule::in(array_map(fn (ApiKeyScope $s) => $s->value, ApiKeyScope::restaurantScopes()))],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    /**
     * @return array<int, ApiKeyScope>
     */
    public function scopes(): array
    {
        return array_map(fn (string $s) => ApiKeyScope::from($s), array_unique($this->validated('scopes')));
    }
}

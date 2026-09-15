<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Api\V1\Operator\ApiKeyStoreRequest;
use App\Models\ApiKey;

/**
 * The "Create a key" form on the AI assistant page: the operator API's
 * key rules, except that the per-key rate limit is always chosen.
 */
class AiAssistantKeyRequest extends ApiKeyStoreRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'rate_limit_per_minute' => ['required', 'integer', 'between:1,'.ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scopes.required' => 'Pick at least one permission.',
            'scopes.min' => 'Pick at least one permission.',
            'scopes.*.in' => 'One of the permissions is not available to a restaurant key.',
        ];
    }
}

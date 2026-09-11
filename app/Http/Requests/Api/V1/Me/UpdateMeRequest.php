<?php

namespace App\Http\Requests\Api\V1\Me;

use App\Concerns\ProfileValidationRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Partial profile update: only the fields present are changed.
 */
class UpdateMeRequest extends FormRequest
{
    use ProfileValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', ...$this->nameRules()],
            'email' => ['sometimes', ...$this->emailRules($this->user()->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'push_order_updates' => ['sometimes', 'boolean'],
        ];
    }
}

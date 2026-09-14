<?php

namespace App\Http\Requests\Api\V1\Operator\Platform;

use Illuminate\Foundation\Http\FormRequest;

class EarningsMonthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }
}

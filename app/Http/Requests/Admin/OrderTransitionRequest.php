<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderTransitionRequest extends FormRequest
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
            'to_status' => ['required', 'string', 'in:confirmed,preparing,ready,completed,cancelled'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

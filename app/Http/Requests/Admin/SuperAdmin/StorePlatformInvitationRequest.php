<?php

namespace App\Http\Requests\Admin\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class StorePlatformInvitationRequest extends FormRequest
{
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
            'email' => ['required', 'email', 'max:255'],
            'restaurant_id' => ['nullable', 'integer', 'exists:restaurants,id'],
            'as_super_admin' => ['nullable', 'boolean'],
        ];
    }
}

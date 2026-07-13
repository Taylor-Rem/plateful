<?php

namespace App\Http\Requests\Admin\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformRolesRequest extends FormRequest
{
    /**
     * Access is gated by the `super` middleware on the route.
     */
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
            'founder_id' => ['required', 'integer', 'exists:users,id'],
            'operator_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantRolesRequest extends FormRequest
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
            'recruiter_id' => ['nullable', 'integer', 'exists:users,id'],
            'overseer_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}

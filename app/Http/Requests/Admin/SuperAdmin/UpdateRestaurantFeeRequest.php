<?php

namespace App\Http\Requests\Admin\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRestaurantFeeRequest extends FormRequest
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
            'application_fee_percent' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'application_fee_percent.decimal' => 'The fee may have at most 2 decimal places.',
            'application_fee_percent.between' => 'The fee must be between 0 and 100 percent.',
        ];
    }
}

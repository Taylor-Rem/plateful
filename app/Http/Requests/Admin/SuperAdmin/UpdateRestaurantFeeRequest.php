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
     * The ceiling is a guard against a fat finger, not a business rule: the
     * locked rate is 4% (config/platform.php) and the whole pitch is undercutting
     * the 15–40% the delivery apps take. A `0,100` range would let a stray digit
     * set a predatory rate that silently prices real orders. Keep this in step
     * with the `max` on the fee input (Restaurants/Show.vue).
     */
    public const MAX_PERCENT = 15;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'application_fee_percent' => ['required', 'numeric', 'between:0,'.self::MAX_PERCENT, 'decimal:0,2'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'application_fee_percent.decimal' => 'The fee may have at most 2 decimal places.',
            'application_fee_percent.between' => 'The fee must be between 0 and '.self::MAX_PERCENT.' percent.',
        ];
    }
}

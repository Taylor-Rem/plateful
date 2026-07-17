<?php

namespace App\Http\Requests\Admin;

use App\Services\PhotoConversionService;
use Illuminate\Foundation\Http\FormRequest;

class RestaurantSettingsRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'logo' => ['nullable', 'file', PhotoConversionService::acceptedPhotoMimes(), 'max:5120'],
            'remove_logo' => ['nullable', 'boolean'],
            'tax_rate_percent' => ['nullable', 'numeric', 'between:0,30'],
            'delivery_fee' => ['nullable', 'numeric', 'between:0,500'],
            'pickup_refunds_enabled' => ['nullable', 'boolean'],
            'delivery_refunds_enabled' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('delivery_fee') && $this->input('delivery_fee') !== null && $this->input('delivery_fee') !== '') {
            $this->merge([
                'delivery_fee_cents' => (int) round(((float) $this->input('delivery_fee')) * 100),
            ]);
        }
    }
}

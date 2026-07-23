<?php

namespace App\Http\Requests\Admin;

use App\Services\PhotoConversionService;
use Illuminate\Foundation\Http\FormRequest;

class OnboardingBasicsRequest extends FormRequest
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
            'description' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:32'],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['nullable', 'file', PhotoConversionService::acceptedPhotoMimes(), 'max:5120'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'tax_rate_percent' => ['nullable', 'numeric', 'between:0,30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'state.size' => 'State must be a 2-letter code.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('state')) {
            $this->merge(['state' => strtoupper(trim((string) $this->input('state')))]);
        }
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Services\PhotoConversionService;
use Illuminate\Foundation\Http\FormRequest;

class HeroUpdateRequest extends FormRequest
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
            'hero_tagline' => ['nullable', 'string', 'max:255'],
            'hero_cta_label' => ['nullable', 'string', 'max:64'],
            'hero_cta_url' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'file', PhotoConversionService::acceptedPhotoMimes(), 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Services\PhotoConversionService;
use Illuminate\Foundation\Http\FormRequest;

class AboutUpdateRequest extends FormRequest
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
            'about_body' => ['nullable', 'string', 'max:5000'],
            'image' => ['nullable', 'file', PhotoConversionService::acceptedPhotoMimes(), 'max:8192'],
            'remove_image' => ['nullable', 'boolean'],
        ];
    }
}

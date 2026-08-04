<?php

namespace App\Http\Requests\Admin;

use App\Services\PhotoConversionService;
use Illuminate\Foundation\Http\FormRequest;

class PhotoStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public const MAX_BATCH = 12;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'images' => ['required', 'array', 'min:1', 'max:'.self::MAX_BATCH],
            'images.*' => ['file', PhotoConversionService::acceptedPhotoMimes(), 'max:8192'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class PhotoStoreRequest extends FormRequest
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
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:140'],
        ];
    }
}

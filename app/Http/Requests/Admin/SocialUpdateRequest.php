<?php

namespace App\Http\Requests\Admin;

use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;

class SocialUpdateRequest extends FormRequest
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
        $rules = [
            'social_links' => ['nullable', 'array'],
        ];

        foreach (Restaurant::SOCIAL_PLATFORMS as $platform) {
            $rules["social_links.{$platform}"] = ['nullable', 'string', 'url:http,https', 'max:255'];
        }

        return $rules;
    }
}

<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SocialLoginRequest extends FormRequest
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
            'id_token' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            // Apple only sends the user's name to the app on the very first
            // authorization; the app forwards it here so we can capture it.
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function idToken(): string
    {
        return (string) $this->input('id_token');
    }

    public function deviceName(): string
    {
        return (string) $this->input('device_name');
    }

    public function name(): ?string
    {
        $name = trim((string) $this->input('name', ''));

        return $name === '' ? null : $name;
    }
}

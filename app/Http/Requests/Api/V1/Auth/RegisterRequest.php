<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    use PasswordValidationRules, ProfileValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Same rules as the web CreateNewUser action, minus the tenant: an app
     * account is a plain Plateful account until its first order or favorite
     * associates it with a restaurant.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules(),
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => $this->passwordRules(),
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    public function deviceName(): string
    {
        return (string) $this->input('device_name');
    }
}

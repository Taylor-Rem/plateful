<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class OwnerSignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately minimal: everything not needed to create the account and
     * claim a storefront URL (phone, address, branding, menu) is collected in
     * the onboarding wizard instead. No password confirmation — the field has
     * a show-password toggle and there's always the reset flow.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    public function rules(): array
    {
        $reserved = (array) config('platform.reserved_subdomains', []);

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class, 'email'),
            ],
            'password' => ['required', 'string', Password::default()],

            'restaurant_name' => ['required', 'string', 'max:255'],
            'subdomain' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn($reserved),
                Rule::unique('restaurants', 'subdomain'),
            ],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subdomain.regex' => 'Only lowercase letters, numbers, and hyphens (no leading, trailing, or double hyphens).',
            'subdomain.not_in' => 'That address is reserved. Please choose another.',
            'subdomain.unique' => 'That address is already taken.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->filled('subdomain')) {
            $payload['subdomain'] = strtolower(trim((string) $this->input('subdomain')));
        }
        if ($this->filled('email')) {
            $payload['email'] = strtolower(trim((string) $this->input('email')));
        }

        $this->merge($payload);
    }
}

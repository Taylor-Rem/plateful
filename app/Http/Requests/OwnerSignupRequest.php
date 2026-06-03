<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use App\Support\Menus\MenuPresets;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OwnerSignupRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
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
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => $this->passwordRules(),

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
            'custom_domain' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'menu_preset' => ['nullable', 'string', Rule::in(MenuPresets::cuisines())],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subdomain.regex' => 'The subdomain may only contain lowercase letters, numbers, and hyphens (no leading, trailing, or double hyphens).',
            'subdomain.not_in' => 'That subdomain is reserved. Please choose another.',
            'subdomain.unique' => 'That subdomain is already taken.',
            'custom_domain.regex' => 'Enter a valid domain (e.g. pizzajoint.com).',
            'menu_preset.in' => 'Choose one of the available starter menus, or start blank.',
            'state.size' => 'State must be a 2-letter code.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->filled('subdomain')) {
            $payload['subdomain'] = strtolower(trim((string) $this->input('subdomain')));
        }
        if ($this->filled('custom_domain')) {
            $payload['custom_domain'] = strtolower(trim((string) $this->input('custom_domain')));
        }
        if ($this->filled('email')) {
            $payload['email'] = strtolower(trim((string) $this->input('email')));
        }
        if ($this->filled('state')) {
            $payload['state'] = strtoupper(trim((string) $this->input('state')));
        }

        $this->merge($payload);
    }
}

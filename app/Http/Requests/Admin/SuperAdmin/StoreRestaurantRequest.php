<?php

namespace App\Http\Requests\Admin\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestaurantRequest extends FormRequest
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
        $reserved = (array) config('platform.reserved_subdomains', []);
        $timezones = (array) config('platform.timezones', []);

        return [
            'name' => ['required', 'string', 'max:255'],
            'subdomain' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn($reserved),
                Rule::unique('restaurants', 'subdomain'),
            ],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'street' => ['nullable', 'string', 'max:255'],
            'street2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'size:2'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'timezone' => ['required', 'string', Rule::in($timezones)],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'description' => ['nullable', 'string', 'max:1000'],
            'tax_rate_percent' => ['nullable', 'numeric', 'between:0,30'],
            'delivery_fee' => ['nullable', 'numeric', 'between:0,500'],
            'owner_email' => ['nullable', 'email', 'max:255'],
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
            'state.size' => 'State must be a 2-letter code.',
            'country.size' => 'Country must be a 2-letter code.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->has('subdomain') && is_string($this->input('subdomain'))) {
            $payload['subdomain'] = strtolower(trim($this->input('subdomain')));
        }

        foreach (['name', 'email', 'phone', 'street', 'street2', 'city', 'state', 'postal_code', 'country', 'timezone', 'description', 'owner_email'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $value = trim($this->input($field));
                $payload[$field] = $value === '' ? null : $value;
            }
        }

        if (isset($payload['state']) && is_string($payload['state'])) {
            $payload['state'] = strtoupper($payload['state']);
        }
        if (isset($payload['country']) && is_string($payload['country'])) {
            $payload['country'] = strtoupper($payload['country']);
        }

        if ($this->has('delivery_fee') && $this->input('delivery_fee') !== null && $this->input('delivery_fee') !== '') {
            $payload['delivery_fee_cents'] = (int) round(((float) $this->input('delivery_fee')) * 100);
        } else {
            $payload['delivery_fee_cents'] = 0;
        }

        $this->merge($payload);
    }
}

<?php

namespace App\Http\Requests\Admin\SuperAdmin;

use App\Models\Restaurant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantDomainRequest extends FormRequest
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
        $restaurant = $this->route('restaurant');
        $restaurantId = $restaurant instanceof Restaurant ? $restaurant->id : null;
        $primaryDomain = strtolower((string) config('platform.primary_domain'));

        return [
            'name' => ['required', 'string', 'max:255'],
            'subdomain' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn($reserved),
                // Unique across ALL restaurants, including soft-deleted ones,
                // since a trashed row still holds its subdomain.
                Rule::unique('restaurants', 'subdomain')->ignore($restaurantId),
            ],
            'custom_domain' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/',
                // A custom domain can't live under the platform's own domain —
                // that space is the subdomain system, not a bring-your-own host.
                function (string $attribute, mixed $value, \Closure $fail) use ($primaryDomain): void {
                    $host = strtolower((string) $value);
                    if ($host === $primaryDomain || str_ends_with($host, '.'.$primaryDomain)) {
                        $fail("A custom domain can't be under {$primaryDomain}. Use the subdomain field for that.");
                    }
                },
                Rule::unique('restaurants', 'custom_domain')->ignore($restaurantId),
            ],
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
            'custom_domain.regex' => 'Enter a valid domain (e.g. pizzajoint.com).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $payload = [];

        if ($this->has('subdomain') && is_string($this->input('subdomain'))) {
            $payload['subdomain'] = strtolower(trim($this->input('subdomain')));
        }

        if ($this->has('name') && is_string($this->input('name'))) {
            $payload['name'] = trim($this->input('name'));
        }

        if ($this->has('custom_domain') && is_string($this->input('custom_domain'))) {
            $domain = strtolower(trim($this->input('custom_domain')));
            $payload['custom_domain'] = $domain === '' ? null : $domain;
        }

        $this->merge($payload);
    }
}

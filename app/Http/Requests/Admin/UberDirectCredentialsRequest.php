<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UberDirectCredentialsRequest extends FormRequest
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
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:255'],
            'customer_id' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Owners paste these out of the Uber dashboard, so trailing whitespace is
     * the most likely way a correct credential arrives broken.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_map(
            fn (mixed $value): mixed => is_string($value) ? trim($value) : $value,
            $this->only(['client_id', 'client_secret', 'customer_id']),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'client_id.required' => 'Paste the Client ID from direct.uber.com → Management → Developer.',
            'client_secret.required' => 'Paste the Client Secret from direct.uber.com → Management → Developer.',
            'customer_id.required' => 'Paste the Customer ID from direct.uber.com → Management → Developer.',
        ];
    }
}

<?php

namespace App\Http\Requests\Api\V1;

use App\Services\CartManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The storefront CheckoutRequest's rules for the app, with the tip as plain
 * cents (the app renders its own presets) and no session-side preset math.
 */
class CheckoutIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cart = app(CartManager::class)->current();

        return $cart !== null && $cart->items()->exists();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->id;

        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['required_if:type,delivery', 'nullable', 'string', 'max:32'],
            'type' => ['required', Rule::in(['delivery', 'pickup'])],
            'delivery_address' => ['required_if:type,delivery', 'array'],
            'delivery_address.street' => ['required_if:type,delivery', 'nullable', 'string', 'max:255'],
            'delivery_address.street2' => ['nullable', 'string', 'max:255'],
            'delivery_address.city' => ['required_if:type,delivery', 'nullable', 'string', 'max:255'],
            'delivery_address.state' => ['required_if:type,delivery', 'nullable', 'string', 'max:32'],
            'delivery_address.postal_code' => ['required_if:type,delivery', 'nullable', 'string', 'max:20'],
            'delivery_address.country' => ['nullable', 'string', 'max:64'],
            'delivery_address.instructions' => ['nullable', 'string', 'max:1000'],
            'delivery_quote_token' => ['nullable', 'string', 'max:64'],
            'address_id' => [
                'nullable',
                'integer',
                Rule::exists('addresses', 'id')->where(fn ($q) => $userId ? $q->where('user_id', $userId) : $q->whereRaw('1 = 0')),
            ],
            'save_address' => ['nullable', 'boolean'],
            'marketing_opt_in' => ['nullable', 'boolean'],
            'tip_cents' => ['nullable', 'integer', 'min:0', 'max:50000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_phone.required_if' => 'A phone number is required for delivery so the courier can reach you.',
        ];
    }
}

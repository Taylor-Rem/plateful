<?php

namespace App\Http\Requests\Storefront;

use App\Services\CartManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        $cart = app(CartManager::class)->current();

        return $cart !== null && $cart->items()->exists();
    }

    protected function prepareForValidation(): void
    {
        $cart = app(CartManager::class)->current();
        $subtotal = 0;
        if ($cart) {
            $cart->loadMissing('items');
            foreach ($cart->items as $line) {
                $subtotal += (int) $line->unit_price_cents * (int) $line->quantity;
            }
        }

        $preset = $this->input('tip_preset');
        $tipCents = 0;

        if ($preset === 'custom') {
            $tipCents = max(0, (int) $this->input('tip_custom_cents', 0));
        } elseif (is_numeric($preset)) {
            $pct = (float) $preset;
            $tipCents = (int) round($subtotal * $pct / 100);
        }

        $this->merge(['tip_cents' => $tipCents]);
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
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'type' => ['required', Rule::in(['delivery', 'pickup'])],
            'delivery_address' => ['required_if:type,delivery', 'array'],
            'delivery_address.street' => ['required_if:type,delivery', 'nullable', 'string', 'max:255'],
            'delivery_address.street2' => ['nullable', 'string', 'max:255'],
            'delivery_address.city' => ['required_if:type,delivery', 'nullable', 'string', 'max:255'],
            'delivery_address.state' => ['required_if:type,delivery', 'nullable', 'string', 'max:32'],
            'delivery_address.postal_code' => ['required_if:type,delivery', 'nullable', 'string', 'max:20'],
            'delivery_address.country' => ['nullable', 'string', 'max:64'],
            'delivery_address.instructions' => ['nullable', 'string', 'max:1000'],
            // Opaque handle on the server-side quote. Its existence, address
            // match, and expiry are checked in OrderPlacement::prepare() —
            // the fee itself is never accepted from the client.
            'delivery_quote_token' => ['nullable', 'string', 'max:64'],
            'address_id' => [
                'nullable',
                'integer',
                Rule::exists('addresses', 'id')->where(fn ($q) => $userId ? $q->where('user_id', $userId) : $q->whereRaw('1 = 0')),
            ],
            'save_address' => ['nullable', 'boolean'],
            'tip_preset' => ['nullable', Rule::in(['0', '15', '18', '20', 'custom', 0, 15, 18, 20])],
            'tip_custom_cents' => ['nullable', 'integer', 'min:0', 'max:50000'],
            'tip_cents' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

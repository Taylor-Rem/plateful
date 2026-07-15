<?php

namespace App\Http\Requests\Admin;

use App\Enums\DeliveryFallbackAction;
use App\Enums\DeliveryFeeStrategy;
use App\Enums\DeliveryMode;
use App\Enums\SelfDeliveryTipRecipient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DeliverySettingsRequest extends FormRequest
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
            'delivery_enabled' => ['required', 'boolean'],
            'delivery_mode' => ['nullable', Rule::enum(DeliveryMode::class)],
            'delivery_fee' => ['nullable', 'numeric', 'between:0,500'],
            'delivery_fee_strategy' => ['required', Rule::enum(DeliveryFeeStrategy::class)],
            // 0 is legitimate for a counter-service kitchen with food ready now.
            // The upper bound is a typo guard, not a policy.
            'prep_time_minutes' => ['required', 'integer', 'between:0,180'],
            'self_delivery_tip_recipient' => ['required', Rule::enum(SelfDeliveryTipRecipient::class)],
            'delivery_fallback_action' => ['required', Rule::enum(DeliveryFallbackAction::class)],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $this->boolean('delivery_enabled')) {
                    return;
                }

                // Without a mode the dispatcher silently treats a restaurant as
                // third-party, so an owner who just flips delivery on gets
                // couriers they never asked for. Make the choice explicit at
                // the only moment it is cheap to ask.
                if ($this->input('delivery_mode') === null) {
                    $validator->errors()->add(
                        'delivery_mode',
                        'Choose who delivers: your own drivers, or a courier network.',
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('delivery_fee') && $this->input('delivery_fee') !== null && $this->input('delivery_fee') !== '') {
            $this->merge([
                'delivery_fee_cents' => (int) round(((float) $this->input('delivery_fee')) * 100),
            ]);
        }
    }
}

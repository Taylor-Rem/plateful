<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A swap set is a reusable template with one pick-one group ("Cheeses":
 * provolone, mozzarella +$0.75, swiss +$0.50). This is the inline
 * quick-create from the Ingredients panel; the full template form still
 * exists for everything else.
 */
class SwapSetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $options = $this->input('options');
        if (! is_array($options)) {
            return;
        }

        $this->merge(['options' => array_values(array_map(function ($o) {
            if (is_array($o) && array_key_exists('price_delta', $o)) {
                $o['price_delta_cents'] = (int) round(((float) ($o['price_delta'] === '' ? 0 : $o['price_delta'])) * 100);
            }

            return $o;
        }, $options))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'allow_none' => ['boolean'],
            'options' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.name' => ['required', 'string', 'max:120', 'distinct:ignore_case'],
            'options.*.price_delta' => ['nullable', 'numeric', 'between:-999.99,999.99'],
            'options.*.price_delta_cents' => ['nullable', 'integer'],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The reviewed-and-edited menu draft the owner confirmed. Prices must be
 * positive here: extraction marks unreadable prices as 0, and those must be
 * fixed on the review screen before the menu can be imported.
 */
class MenuImportConfirmRequest extends FormRequest
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
            'categories' => ['required', 'array', 'min:1', 'max:'.config('menu_import.max_categories')],
            'categories.*.name' => ['required', 'string', 'max:80'],
            'categories.*.items' => ['required', 'array', 'min:1'],
            'categories.*.items.*.name' => ['required', 'string', 'max:120'],
            'categories.*.items.*.description' => ['nullable', 'string', 'max:500'],
            'categories.*.items.*.price_cents' => ['required', 'integer', 'min:1', 'max:'.config('menu_import.max_price_cents')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categories.*.items.*.price_cents.min' => 'Every item needs a price before importing.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $total = collect((array) $this->input('categories', []))
                ->sum(fn ($category) => count((array) ($category['items'] ?? [])));

            if ($total > (int) config('menu_import.max_items')) {
                $v->errors()->add('categories', 'That menu has too many items to import at once.');
            }
        });
    }
}

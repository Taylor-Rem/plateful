<?php

namespace App\Http\Requests\Storefront;

use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
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
            'quantity' => ['nullable', 'integer', 'min:1', 'max:50'],
            'option_ids' => ['nullable', 'array'],
            'option_ids.*' => ['integer'],
            'notes' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function notes(): ?string
    {
        $notes = trim((string) $this->input('notes', ''));

        return $notes === '' ? null : $notes;
    }

    public function quantity(): int
    {
        return (int) ($this->input('quantity') ?? 1);
    }

    /**
     * @return array<int, int>
     */
    public function optionIds(): array
    {
        $raw = $this->input('option_ids', []);
        if (! is_array($raw)) {
            return [];
        }

        return array_map(fn ($v) => (int) $v, $raw);
    }
}

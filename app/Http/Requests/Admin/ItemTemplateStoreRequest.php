<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ItemTemplateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $groups = $this->input('groups');
        if (! is_array($groups)) {
            return;
        }

        $this->merge([
            'groups' => array_values(array_map(function ($g) {
                if (! is_array($g)) {
                    return $g;
                }
                if (isset($g['options']) && is_array($g['options'])) {
                    $g['options'] = array_values(array_map(function ($o) {
                        if (is_array($o) && array_key_exists('price_delta', $o)) {
                            $o['price_delta_cents'] = (int) round(((float) $o['price_delta']) * 100);
                        }

                        return $o;
                    }, $g['options']));
                }

                return $g;
            }, $groups)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'groups' => ['nullable', 'array'],
            'groups.*.id' => ['nullable', 'integer'],
            'groups.*.name' => ['required', 'string', 'max:255'],
            'groups.*.min_selections' => ['integer', 'min:0'],
            'groups.*.max_selections' => ['nullable', 'integer', 'gte:groups.*.min_selections'],
            'groups.*.options' => ['nullable', 'array'],
            'groups.*.options.*.id' => ['nullable', 'integer'],
            'groups.*.options.*.name' => ['required', 'string', 'max:255'],
            'groups.*.options.*.price_delta' => ['required', 'numeric', 'between:-999.99,999.99'],
            'groups.*.options.*.is_available' => ['boolean'],
        ];
    }
}

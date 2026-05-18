<?php

namespace App\Http\Requests\Admin;

use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuItemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->filled('price')) {
            $data['price_cents'] = (int) round(((float) $this->input('price')) * 100);
        }

        $modifiers = $this->input('modifiers');
        if (is_array($modifiers)) {
            $data['modifiers'] = array_values(array_map(function ($m) {
                if (is_array($m) && array_key_exists('price_delta', $m)) {
                    $m['price_delta_cents'] = (int) round(((float) $m['price_delta']) * 100);
                }

                return $m;
            }, $modifiers));
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $itemId = $this->route('item')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'alpha_dash',
                'max:255',
                Rule::unique('menu_items', 'slug')
                    ->where(fn ($q) => $q->where('restaurant_id', $tenantId))
                    ->ignore($itemId),
            ],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'menu_category_id' => [
                'required',
                Rule::exists('menu_categories', 'id')->where(fn ($q) => $q->where('restaurant_id', $tenantId)),
            ],
            'is_available' => ['boolean'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
            'modifiers' => ['nullable', 'array'],
            'modifiers.*.id' => ['nullable', 'integer'],
            'modifiers.*.name' => ['required_with:modifiers', 'string', 'max:255'],
            'modifiers.*.group_label' => ['nullable', 'string', 'max:255'],
            'modifiers.*.price_delta' => ['required_with:modifiers', 'numeric', 'between:-999.99,999.99'],
            'modifiers.*.is_default' => ['boolean'],
        ];
    }
}

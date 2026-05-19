<?php

namespace App\Http\Requests\Admin;

use App\Tenancy\CurrentTenant;
use Illuminate\Validation\Rule;

class MenuItemUpdateRequest extends MenuItemStoreRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $itemId = $this->route('item')?->id;

        $rules = parent::rules();

        $rules['slug'] = [
            'nullable',
            'string',
            'alpha_dash',
            'max:255',
            Rule::unique('menu_items', 'slug')
                ->where(fn ($q) => $q->where('restaurant_id', $tenantId))
                ->ignore($itemId),
        ];

        return $rules;
    }
}

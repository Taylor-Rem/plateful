<?php

namespace App\Http\Requests\Admin;

use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuItemReorderRequest extends FormRequest
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
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'category_id' => [
                'required',
                Rule::exists('menu_categories', 'id')->where(fn ($q) => $q->where('restaurant_id', $tenantId)),
            ],
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => [
                'integer',
                Rule::exists('menu_items', 'id')->where(fn ($q) => $q->where('restaurant_id', $tenantId)),
            ],
        ];
    }
}

<?php

namespace App\Http\Requests\Admin;

use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuCategoryUpdateRequest extends FormRequest
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
        $categoryId = $this->route('category')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'slug' => [
                'nullable',
                'string',
                'alpha_dash',
                'max:255',
                Rule::unique('menu_categories', 'slug')
                    ->where(fn ($q) => $q->where('restaurant_id', $tenantId))
                    ->ignore($categoryId),
            ],
        ];
    }
}

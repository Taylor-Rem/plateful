<?php

namespace App\Http\Requests\Admin;

/**
 * Same row shape as {@see MenuItemIngredientsRequest}, applied by name to
 * every item in a category. Ids are meaningless here and ignored.
 */
class ApplyIngredientRulesRequest extends MenuItemIngredientsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['ingredients.*.id']);

        $rules['ingredients'] = ['required', 'array', 'min:1', 'max:40'];

        return $rules;
    }
}

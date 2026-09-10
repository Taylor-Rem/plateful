<?php

namespace App\Http\Requests\Admin;

use App\Models\ItemTemplate;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
        $maxPrice = config('menu_import.max_price_cents');
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'categories' => ['required', 'array', 'min:1', 'max:'.config('menu_import.max_categories')],
            'categories.*.name' => ['required', 'string', 'max:80'],
            'categories.*.items' => ['required', 'array', 'min:1'],
            'categories.*.items.*.name' => ['required', 'string', 'max:120'],
            'categories.*.items.*.description' => ['nullable', 'string', 'max:500'],
            'categories.*.items.*.price_cents' => ['required', 'integer', 'min:1', 'max:'.$maxPrice],
            'categories.*.items.*.option_set' => ['nullable', 'string', 'max:80'],
            // Customizations from the wizard step: one row per ingredient
            // with the owner's rules. A swap either reuses an existing swap
            // set (swap_template_id) or defines a new one inline (swap_set),
            // shared across the import by name.
            'categories.*.items.*.ingredients' => ['nullable', 'array', 'max:40'],
            'categories.*.items.*.ingredients.*.name' => ['required', 'string', 'max:120'],
            'categories.*.items.*.ingredients.*.is_removable' => ['boolean'],
            'categories.*.items.*.ingredients.*.extra_price_cents' => ['nullable', 'integer', 'min:0', 'max:'.$maxPrice],
            'categories.*.items.*.ingredients.*.swap_template_id' => [
                'nullable',
                'integer',
                Rule::exists('item_templates', 'id')->where(fn ($q) => $q->where('restaurant_id', $tenantId)),
            ],
            'categories.*.items.*.ingredients.*.swap_set' => ['nullable', 'array'],
            'categories.*.items.*.ingredients.*.swap_set.name' => ['required_with:categories.*.items.*.ingredients.*.swap_set', 'string', 'max:80'],
            'categories.*.items.*.ingredients.*.swap_set.options' => ['required_with:categories.*.items.*.ingredients.*.swap_set', 'array', 'min:1', 'max:30'],
            'categories.*.items.*.ingredients.*.swap_set.options.*.name' => ['required', 'string', 'max:120'],
            'categories.*.items.*.ingredients.*.swap_set.options.*.price_delta_cents' => ['nullable', 'integer', 'min:-'.$maxPrice, 'max:'.$maxPrice],
            'option_sets' => ['nullable', 'array', 'max:'.config('menu_import.max_option_sets')],
            'option_sets.*.name' => ['required', 'string', 'max:80', 'distinct'],
            'option_sets.*.groups' => ['required', 'array', 'min:1', 'max:'.config('menu_import.max_groups_per_set')],
            'option_sets.*.groups.*.name' => ['required', 'string', 'max:80'],
            'option_sets.*.groups.*.min_selections' => ['required', 'integer', 'min:0'],
            'option_sets.*.groups.*.max_selections' => ['nullable', 'integer', 'min:1', 'gte:option_sets.*.groups.*.min_selections'],
            'option_sets.*.groups.*.options' => ['required', 'array', 'min:1', 'max:'.config('menu_import.max_options_per_group')],
            'option_sets.*.groups.*.options.*.name' => ['required', 'string', 'max:120'],
            'option_sets.*.groups.*.options.*.price_delta_cents' => ['required', 'integer', 'min:-'.$maxPrice, 'max:'.$maxPrice],
            'option_sets.*.groups.*.options.*.is_default' => ['required', 'boolean'],
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

            $setNames = collect((array) $this->input('option_sets', []))
                ->pluck('name')
                ->filter(fn ($name) => is_string($name))
                ->all();

            foreach ((array) $this->input('categories', []) as $catIndex => $category) {
                foreach ((array) ($category['items'] ?? []) as $itemIndex => $item) {
                    $ref = $item['option_set'] ?? null;
                    if (is_string($ref) && ! in_array($ref, $setNames, true)) {
                        $v->errors()->add(
                            "categories.{$catIndex}.items.{$itemIndex}.option_set",
                            "\"{$ref}\" doesn't match any option set.",
                        );
                    }
                }
            }

            // Existing swap sets referenced from carried-over customizations
            // must still be swap-set shaped.
            $swapIds = collect((array) $this->input('categories', []))
                ->flatMap(fn ($c) => collect((array) ($c['items'] ?? []))->flatMap(fn ($i) => collect((array) ($i['ingredients'] ?? []))->pluck('swap_template_id')))
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique();
            if ($swapIds->isNotEmpty()) {
                $notSwapSets = ItemTemplate::query()->with('groups')->findMany($swapIds)
                    ->reject(fn (ItemTemplate $t) => $t->isSwapSet())
                    ->pluck('name');
                if ($notSwapSets->isNotEmpty()) {
                    $v->errors()->add('categories', '"'.$notSwapSets->first().'" is not a swap set — it needs exactly one pick-one group.');
                }
            }

            foreach ((array) $this->input('option_sets', []) as $setIndex => $set) {
                foreach ((array) ($set['groups'] ?? []) as $groupIndex => $group) {
                    $min = (int) ($group['min_selections'] ?? 0);
                    if ($min > count((array) ($group['options'] ?? []))) {
                        $v->errors()->add(
                            "option_sets.{$setIndex}.groups.{$groupIndex}.min_selections",
                            'A group can\'t require more selections than it has options.',
                        );
                    }
                }
            }
        });
    }
}

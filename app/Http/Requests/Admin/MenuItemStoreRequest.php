<?php

namespace App\Http\Requests\Admin;

use App\Models\ItemTemplate;
use App\Services\PhotoConversionService;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuItemStoreRequest extends FormRequest
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

        $ids = $this->input('default_selection_ids');
        if (is_array($ids)) {
            $data['default_selection_ids'] = array_values(array_map(
                fn ($v) => (int) $v,
                array_filter($ids, fn ($v) => $v !== null && $v !== ''),
            ));
        }

        if ($this->input('item_template_id') === '' || $this->input('item_template_id') === 'null') {
            $data['item_template_id'] = null;
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

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'alpha_dash',
                'max:255',
                Rule::unique('menu_items', 'slug')->where(fn ($q) => $q->where('restaurant_id', $tenantId)),
            ],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'menu_category_id' => [
                'required',
                Rule::exists('menu_categories', 'id')->where(fn ($q) => $q->where('restaurant_id', $tenantId)),
            ],
            'is_available' => ['boolean'],
            'is_featured' => ['boolean'],
            'image' => ['nullable', 'file', PhotoConversionService::acceptedPhotoMimes(), 'max:5120'],
            'remove_image' => ['nullable', 'boolean'],
            'item_template_id' => [
                'nullable',
                'integer',
                Rule::exists('item_templates', 'id')->where(fn ($q) => $q->where('restaurant_id', $tenantId)),
            ],
            'default_selection_ids' => ['nullable', 'array'],
            'default_selection_ids.*' => ['integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $templateId = $this->input('item_template_id');
            $rawSelections = $this->input('default_selection_ids', []);
            $selections = is_array($rawSelections)
                ? array_values(array_filter(array_map('intval', $rawSelections)))
                : [];

            if ($templateId === null || $templateId === '') {
                if (! empty($selections)) {
                    $v->errors()->add('default_selection_ids', 'Default selections require a template.');
                }

                return;
            }

            $template = ItemTemplate::with('groups.options')->find($templateId);
            if (! $template) {
                return;
            }

            $allOwnedOptionIds = [];
            foreach ($template->groups as $group) {
                foreach ($group->options as $opt) {
                    $allOwnedOptionIds[$opt->id] = $group->id;
                }
            }

            // Any selected option must belong to this template.
            foreach ($selections as $optId) {
                if (! array_key_exists($optId, $allOwnedOptionIds)) {
                    $v->errors()->add(
                        'default_selection_ids',
                        'Default selections include an option that does not belong to the chosen template.',
                    );

                    return;
                }
            }

            foreach ($template->groups as $group) {
                $countInGroup = collect($selections)
                    ->filter(fn ($id) => ($allOwnedOptionIds[$id] ?? null) === $group->id)
                    ->count();

                if ($countInGroup < $group->min_selections) {
                    $v->errors()->add(
                        'default_selection_ids',
                        "Group \"{$group->name}\" requires at least {$group->min_selections} default selection(s).",
                    );
                }

                if ($group->max_selections !== null && $countInGroup > $group->max_selections) {
                    $v->errors()->add(
                        'default_selection_ids',
                        "Group \"{$group->name}\" allows at most {$group->max_selections} default selection(s).",
                    );
                }
            }
        });
    }
}

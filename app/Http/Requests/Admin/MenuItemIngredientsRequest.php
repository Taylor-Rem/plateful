<?php

namespace App\Http\Requests\Admin;

use App\Models\ItemTemplate;
use App\Tenancy\CurrentTenant;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MenuItemIngredientsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $rows = $this->input('ingredients');
        if (! is_array($rows)) {
            return;
        }

        $this->merge(['ingredients' => array_values(array_map(function ($row) {
            if (! is_array($row)) {
                return $row;
            }

            $price = $row['extra_price'] ?? null;
            $row['extra_price_cents'] = ($price === null || $price === '')
                ? null
                : (int) round(((float) $price) * 100);

            if (($row['swap_template_id'] ?? null) === '' || ($row['swap_template_id'] ?? null) === 'null') {
                $row['swap_template_id'] = null;
            }

            return $row;
        }, $rows))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'ingredients' => ['present', 'array', 'max:40'],
            'ingredients.*.id' => ['nullable', 'integer'],
            'ingredients.*.name' => ['required', 'string', 'max:120'],
            'ingredients.*.is_removable' => ['boolean'],
            'ingredients.*.allow_half' => ['boolean'],
            'ingredients.*.extra_price' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'ingredients.*.extra_price_cents' => ['nullable', 'integer', 'min:0'],
            'ingredients.*.swap_template_id' => [
                'nullable',
                'integer',
                Rule::exists('item_templates', 'id')->where(fn ($q) => $q->where('restaurant_id', $tenantId)),
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $ids = collect((array) $this->input('ingredients', []))
                ->pluck('swap_template_id')
                ->filter(fn ($id) => is_numeric($id))
                ->map(fn ($id) => (int) $id)
                ->unique();

            if ($ids->isEmpty()) {
                return;
            }

            $templates = ItemTemplate::query()->with('groups')->findMany($ids)->keyBy('id');

            foreach ((array) $this->input('ingredients', []) as $index => $row) {
                $id = $row['swap_template_id'] ?? null;
                if (! is_numeric($id)) {
                    continue;
                }

                $template = $templates->get((int) $id);
                if ($template && ! $template->isSwapSet()) {
                    $v->errors()->add(
                        "ingredients.{$index}.swap_template_id",
                        "\"{$template->name}\" is not a swap set — it needs exactly one pick-one group.",
                    );
                }
            }
        });
    }

    /**
     * @return array<int, array{id: int|null, name: string, is_removable: bool, allow_half: bool, extra_price_cents: int|null, swap_template_id: int|null}>
     */
    public function rows(): array
    {
        return array_map(fn (array $row) => [
            'id' => isset($row['id']) ? (int) $row['id'] : null,
            'name' => (string) $row['name'],
            'is_removable' => (bool) ($row['is_removable'] ?? true),
            'allow_half' => (bool) ($row['allow_half'] ?? true),
            'extra_price_cents' => isset($row['extra_price_cents']) ? (int) $row['extra_price_cents'] : null,
            'swap_template_id' => isset($row['swap_template_id']) ? (int) $row['swap_template_id'] : null,
        ], $this->validated('ingredients', []));
    }
}

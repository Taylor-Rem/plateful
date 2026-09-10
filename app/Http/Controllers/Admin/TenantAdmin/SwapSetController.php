<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SwapSetStoreRequest;
use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class SwapSetController extends Controller
{
    /** Console variant: the route carries `{restaurant}` first (see MenuItemIngredientController). */
    public function storeInConsole(SwapSetStoreRequest $request, Restaurant $restaurant, CurrentTenant $tenant): RedirectResponse
    {
        return $this->store($request, $tenant);
    }

    public function store(SwapSetStoreRequest $request, CurrentTenant $tenant): RedirectResponse
    {
        $restaurant = $tenant->get();
        $this->authorize('create', [MenuItem::class, $restaurant]);

        $validated = $request->validated();

        $template = DB::transaction(function () use ($restaurant, $validated) {
            $template = ItemTemplate::create([
                'restaurant_id' => $restaurant->id,
                'name' => $validated['name'],
                'description' => null,
                'is_active' => true,
                'position' => 0,
            ]);

            $group = ItemTemplateGroup::create([
                'item_template_id' => $template->id,
                'name' => $validated['name'],
                'kind' => ItemTemplateGroup::KIND_SWAP,
                'min_selections' => ($validated['allow_none'] ?? false) ? 0 : 1,
                'max_selections' => 1,
                'position' => 0,
            ]);

            foreach (array_values($validated['options']) as $index => $option) {
                ItemTemplateOption::create([
                    'item_template_group_id' => $group->id,
                    'kind' => ItemTemplateOption::KIND_CHOICE,
                    'name' => $option['name'],
                    'price_delta_cents' => (int) ($option['price_delta_cents'] ?? 0),
                    'is_available' => true,
                    'position' => $index,
                ]);
            }

            return $template;
        });

        return back()
            ->with('success', "Created swap set \"{$template->name}\".")
            ->with('createdSwapSetId', $template->id);
    }
}

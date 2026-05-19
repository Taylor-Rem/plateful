<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\ItemTemplateData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ItemTemplateStoreRequest;
use App\Http\Requests\Admin\ItemTemplateUpdateRequest;
use App\Models\ItemTemplate;
use App\Models\ItemTemplateGroup;
use App\Models\ItemTemplateOption;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ItemTemplateController extends Controller
{
    public function index(Restaurant $restaurant): Response
    {
        $templates = ItemTemplate::query()
            ->where('restaurant_id', $restaurant->id)
            ->withCount(['groups', 'menuItems'])
            ->orderBy('name')
            ->get()
            ->map(fn (ItemTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'isActive' => (bool) $t->is_active,
                'groupsCount' => (int) $t->groups_count,
                'menuItemsCount' => (int) $t->menu_items_count,
            ])
            ->all();

        return Inertia::render('Admin/TenantAdmin/Templates/Index', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'templates' => $templates,
        ]);
    }

    public function create(Restaurant $restaurant): Response
    {
        return Inertia::render('Admin/TenantAdmin/Templates/Create', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'template' => null,
        ]);
    }

    public function store(ItemTemplateStoreRequest $request, Restaurant $restaurant): RedirectResponse
    {
        $validated = $request->validated();

        $template = DB::transaction(function () use ($restaurant, $validated, $request) {
            $template = ItemTemplate::create([
                'restaurant_id' => $restaurant->id,
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? true),
                'position' => 0,
            ]);

            $this->syncGroups($template, $request->input('groups', []) ?? []);

            return $template;
        });

        return redirect()
            ->to("/{$restaurant->subdomain}/menu/templates")
            ->with('success', "Created \"{$template->name}\".");
    }

    public function edit(Restaurant $restaurant, ItemTemplate $template): Response
    {
        $template->load(['groups.options']);

        return Inertia::render('Admin/TenantAdmin/Templates/Edit', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'template' => ItemTemplateData::fromModel($template),
        ]);
    }

    public function update(ItemTemplateUpdateRequest $request, Restaurant $restaurant, ItemTemplate $template): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($template, $validated, $request): void {
            $template->update([
                'name' => $validated['name'],
                'description' => $validated['description'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ]);

            $this->syncGroups($template, $request->input('groups', []) ?? []);
        });

        return redirect()
            ->to("/{$restaurant->subdomain}/menu/templates")
            ->with('success', "Updated \"{$template->name}\".");
    }

    public function destroy(Restaurant $restaurant, ItemTemplate $template): RedirectResponse
    {
        $count = $template->menuItems()->count();

        if ($count > 0) {
            throw ValidationException::withMessages([
                'template' => "This template is used by {$count} menu item(s). Detach them first.",
            ]);
        }

        $name = $template->name;
        $template->delete();

        return back()->with('success', "Deleted \"{$name}\".");
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     */
    protected function syncGroups(ItemTemplate $template, array $groups): void
    {
        $keepGroupIds = [];

        foreach (array_values($groups) as $gIndex => $g) {
            $attrs = [
                'item_template_id' => $template->id,
                'name' => $g['name'],
                'min_selections' => (int) ($g['min_selections'] ?? 0),
                'max_selections' => isset($g['max_selections']) && $g['max_selections'] !== ''
                    ? (int) $g['max_selections']
                    : null,
                'position' => $gIndex,
            ];

            $group = null;
            if (! empty($g['id'])) {
                $group = ItemTemplateGroup::where('item_template_id', $template->id)
                    ->where('id', $g['id'])
                    ->first();
            }

            if ($group) {
                $group->update($attrs);
            } else {
                $group = ItemTemplateGroup::create($attrs);
            }

            $keepGroupIds[] = $group->id;

            $this->syncOptions($group, $g['options'] ?? []);
        }

        // Delete groups no longer present (cascades to options via FK).
        ItemTemplateGroup::where('item_template_id', $template->id)
            ->whereNotIn('id', $keepGroupIds ?: [0])
            ->delete();
    }

    /**
     * @param  array<int, array<string, mixed>>  $options
     */
    protected function syncOptions(ItemTemplateGroup $group, array $options): void
    {
        $keepIds = [];

        foreach (array_values($options) as $oIndex => $o) {
            $attrs = [
                'item_template_group_id' => $group->id,
                'name' => $o['name'],
                'price_delta_cents' => (int) ($o['price_delta_cents'] ?? 0),
                'is_available' => (bool) ($o['is_available'] ?? true),
                'position' => $oIndex,
            ];

            $opt = null;
            if (! empty($o['id'])) {
                $opt = ItemTemplateOption::where('item_template_group_id', $group->id)
                    ->where('id', $o['id'])
                    ->first();
            }

            if ($opt) {
                $opt->update($attrs);
            } else {
                $opt = ItemTemplateOption::create($attrs);
            }

            $keepIds[] = $opt->id;
        }

        ItemTemplateOption::where('item_template_group_id', $group->id)
            ->whereNotIn('id', $keepIds ?: [0])
            ->delete();
    }
}

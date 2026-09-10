<?php

namespace App\Http\Controllers\Storefront\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MenuItemStoreRequest;
use App\Http\Requests\Admin\MenuItemUpdateRequest;
use App\Models\MenuItem;
use App\Services\RestaurantImageService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuItemController extends Controller
{
    public function store(
        MenuItemStoreRequest $request,
        CurrentTenant $tenant,
        RestaurantImageService $images,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $this->authorize('create', [MenuItem::class, $restaurant]);

        $validated = $request->validated();

        $slug = $validated['slug'] ?? Str::slug($validated['name']);
        $slug = $this->ensureUniqueSlug($restaurant->id, $slug);

        $position = (int) (MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('menu_category_id', $validated['menu_category_id'])
            ->max('position') ?? -1) + 1;

        $item = DB::transaction(function () use ($restaurant, $validated, $slug, $position, $request, $images): MenuItem {
            $item = MenuItem::create([
                'restaurant_id' => $restaurant->id,
                'menu_category_id' => $validated['menu_category_id'],
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'price_cents' => (int) $request->input('price_cents'),
                'is_available' => (bool) ($validated['is_available'] ?? true),
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
                'position' => $position,
            ]);

            $this->syncTemplates($item, $validated['template_ids'] ?? []);
            $this->syncDefaultSelections($item, $validated['default_selection_ids'] ?? []);

            if ($request->hasFile('image')) {
                $item->image_path = $images->storeMenuItemImage($item, $request->file('image'));
                $item->save();
            }

            return $item;
        });

        return back()
            ->with('success', "Created \"{$validated['name']}\".")
            ->with('createdMenuItemId', $item->id);
    }

    public function update(
        MenuItemUpdateRequest $request,
        MenuItem $menuItem,
        RestaurantImageService $images,
    ): RedirectResponse {
        $this->authorize('update', $menuItem);

        $validated = $request->validated();

        DB::transaction(function () use ($menuItem, $validated, $request, $images): void {
            $menuItem->update([
                'menu_category_id' => $validated['menu_category_id'],
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? $menuItem->slug,
                'description' => $validated['description'] ?? null,
                'price_cents' => (int) $request->input('price_cents'),
                'is_available' => (bool) ($validated['is_available'] ?? false),
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
            ]);

            $this->syncTemplates($menuItem, $validated['template_ids'] ?? []);
            $this->syncDefaultSelections($menuItem, $validated['default_selection_ids'] ?? []);

            if ($request->boolean('remove_image') && $menuItem->image_path) {
                $images->deleteVariants($menuItem->image_path);
                $menuItem->image_path = null;
                $menuItem->save();
            }

            if ($request->hasFile('image')) {
                $menuItem->image_path = $images->storeMenuItemImage($menuItem, $request->file('image'));
                $menuItem->save();
            }
        });

        return back()->with('success', "Updated \"{$menuItem->name}\".");
    }

    public function destroy(MenuItem $menuItem): RedirectResponse
    {
        $this->authorize('delete', $menuItem);

        $name = $menuItem->name;
        $menuItem->delete();

        return back()->with('success', "Deleted \"{$name}\".");
    }

    /**
     * Replace the hand-attached templates, keeping their order. Templates a
     * swap-set ingredient attached are managed by the ingredient compiler
     * and are left alone.
     *
     * @param  array<int, int>  $templateIds
     */
    protected function syncTemplates(MenuItem $item, array $templateIds): void
    {
        $ingredientAttached = DB::table('menu_item_templates')
            ->where('menu_item_id', $item->id)
            ->whereNotNull('menu_item_ingredient_id')
            ->pluck('item_template_id')
            ->map(fn ($id) => (int) $id);

        $wanted = collect($templateIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => $ingredientAttached->contains($id))
            ->values();

        $sync = [];
        foreach ($wanted as $position => $id) {
            $sync[$id] = ['position' => $position, 'menu_item_ingredient_id' => null];
        }

        DB::table('menu_item_templates')
            ->where('menu_item_id', $item->id)
            ->whereNull('menu_item_ingredient_id')
            ->whereNotIn('item_template_id', $wanted->all() ?: [0])
            ->delete();

        foreach ($sync as $id => $pivot) {
            $item->templates()->syncWithoutDetaching([$id => $pivot]);
        }

        $item->unsetRelation('templates');
    }

    /**
     * Defaults on hand-attached templates come from the form; defaults on
     * the item's own compiled groups (ingredients) are owned by the
     * compiler and preserved here.
     *
     * @param  array<int, int>  $optionIds
     */
    protected function syncDefaultSelections(MenuItem $item, array $optionIds): void
    {
        $item->unsetRelation('ownGroups');
        $item->unsetRelation('templates');

        $ingredientAttached = DB::table('menu_item_templates')
            ->where('menu_item_id', $item->id)
            ->whereNotNull('menu_item_ingredient_id')
            ->pluck('item_template_id')
            ->map(fn ($id) => (int) $id);

        $optionIdsOf = fn ($templates) => $templates
            ->flatMap(fn ($t) => $t->groups->flatMap(fn ($g) => $g->options->pluck('id')))
            ->map(fn ($id) => (int) $id);

        $templates = $item->templates()->with('groups.options')->get();

        // Owned by the compiler: the item's own groups and any swap set an
        // ingredient attached.
        $compilerOptionIds = $item->ownGroups()->with('options')->get()
            ->flatMap(fn ($g) => $g->options->pluck('id'))
            ->map(fn ($id) => (int) $id)
            ->merge($optionIdsOf($templates->filter(fn ($t) => $ingredientAttached->contains((int) $t->id))));

        $handAttachedOptionIds = $optionIdsOf($templates->reject(fn ($t) => $ingredientAttached->contains((int) $t->id)));

        $kept = $item->defaultSelections()->pluck('item_template_options.id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $compilerOptionIds->contains($id));

        $fromForm = collect($optionIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $handAttachedOptionIds->contains($id));

        $item->defaultSelections()->sync($kept->merge($fromForm)->unique()->values()->all());
    }

    protected function ensureUniqueSlug(int $restaurantId, string $base): string
    {
        $slug = $base;
        $i = 2;

        while (MenuItem::withoutTenantScope()
            ->where('restaurant_id', $restaurantId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}

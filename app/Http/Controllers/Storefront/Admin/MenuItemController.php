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

        DB::transaction(function () use ($restaurant, $validated, $slug, $position, $request, $images): void {
            $item = MenuItem::create([
                'restaurant_id' => $restaurant->id,
                'menu_category_id' => $validated['menu_category_id'],
                'item_template_id' => $validated['item_template_id'] ?? null,
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'price_cents' => (int) $request->input('price_cents'),
                'is_available' => (bool) ($validated['is_available'] ?? true),
                'position' => $position,
            ]);

            $this->syncDefaultSelections($item, $validated['default_selection_ids'] ?? []);

            if ($request->hasFile('image')) {
                $item->image_path = $images->storeMenuItemImage($item, $request->file('image'));
                $item->save();
            }
        });

        return redirect()->to('/')->with('success', "Created \"{$validated['name']}\".");
    }

    public function update(
        MenuItemUpdateRequest $request,
        MenuItem $menuItem,
        RestaurantImageService $images,
    ): RedirectResponse {
        $this->authorize('update', $menuItem);

        $validated = $request->validated();

        DB::transaction(function () use ($menuItem, $validated, $request, $images): void {
            $previousTemplateId = $menuItem->item_template_id;
            $newTemplateId = $validated['item_template_id'] ?? null;

            $menuItem->update([
                'menu_category_id' => $validated['menu_category_id'],
                'item_template_id' => $newTemplateId,
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? $menuItem->slug,
                'description' => $validated['description'] ?? null,
                'price_cents' => (int) $request->input('price_cents'),
                'is_available' => (bool) ($validated['is_available'] ?? false),
            ]);

            if ($newTemplateId !== $previousTemplateId) {
                $menuItem->defaultSelections()->detach();
            }

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

        return redirect()->to('/')->with('success', "Deleted \"{$name}\".");
    }

    /**
     * @param  array<int, int>  $optionIds
     */
    protected function syncDefaultSelections(MenuItem $item, array $optionIds): void
    {
        if ($item->item_template_id === null) {
            $item->defaultSelections()->detach();

            return;
        }

        $item->defaultSelections()->sync(array_values(array_unique(array_map('intval', $optionIds))));
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

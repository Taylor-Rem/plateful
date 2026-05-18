<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\MenuItemData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MenuItemReorderRequest;
use App\Http\Requests\Admin\MenuItemStoreRequest;
use App\Http\Requests\Admin\MenuItemUpdateRequest;
use App\Models\MenuItem;
use App\Models\MenuItemModifier;
use App\Models\Restaurant;
use App\Services\RestaurantImageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MenuItemController extends Controller
{
    public function create(Restaurant $restaurant): \Inertia\Response
    {
        $categories = $restaurant->menuCategories()
            ->orderBy('position')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->all();

        return Inertia::render('Admin/TenantAdmin/Items/Create', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'categories' => $categories,
            'item' => null,
        ]);
    }

    public function store(MenuItemStoreRequest $request, Restaurant $restaurant, RestaurantImageService $images): RedirectResponse
    {
        $validated = $request->validated();

        $slug = $validated['slug'] ?? Str::slug($validated['name']);
        $slug = $this->ensureUniqueSlug($restaurant->id, $slug);

        $position = (int) (MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('menu_category_id', $validated['menu_category_id'])
            ->max('position') ?? -1) + 1;

        $item = DB::transaction(function () use ($restaurant, $validated, $slug, $position, $request) {
            $item = MenuItem::create([
                'restaurant_id' => $restaurant->id,
                'menu_category_id' => $validated['menu_category_id'],
                'name' => $validated['name'],
                'slug' => $slug,
                'description' => $validated['description'] ?? null,
                'price_cents' => (int) $request->input('price_cents'),
                'is_available' => (bool) ($validated['is_available'] ?? true),
                'position' => $position,
            ]);

            $this->syncModifiers($item, $request->input('modifiers', []) ?? []);

            return $item;
        });

        if ($request->hasFile('image')) {
            $item->image_path = $images->storeMenuItemImage($item, $request->file('image'));
            $item->save();
        }

        return redirect()->to("/{$restaurant->subdomain}/menu")->with('success', "Created \"{$item->name}\".");
    }

    public function edit(Restaurant $restaurant, MenuItem $item): \Inertia\Response
    {
        $item->load(['modifiers' => fn ($q) => $q->orderBy('position')]);

        $categories = $restaurant->menuCategories()
            ->orderBy('position')
            ->get(['id', 'name'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->all();

        return Inertia::render('Admin/TenantAdmin/Items/Edit', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'categories' => $categories,
            'item' => MenuItemData::fromModel($item),
        ]);
    }

    public function update(MenuItemUpdateRequest $request, Restaurant $restaurant, MenuItem $item, RestaurantImageService $images): RedirectResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($item, $validated, $request): void {
            $item->update([
                'menu_category_id' => $validated['menu_category_id'],
                'name' => $validated['name'],
                'slug' => $validated['slug'] ?? $item->slug,
                'description' => $validated['description'] ?? null,
                'price_cents' => (int) $request->input('price_cents'),
                'is_available' => (bool) ($validated['is_available'] ?? false),
            ]);

            $this->syncModifiers($item, $request->input('modifiers', []) ?? []);
        });

        if ($request->boolean('remove_image') && $item->image_path) {
            $images->deleteVariants($item->image_path);
            $item->image_path = null;
            $item->save();
        }

        if ($request->hasFile('image')) {
            $item->image_path = $images->storeMenuItemImage($item, $request->file('image'));
            $item->save();
        }

        return redirect()->to("/{$restaurant->subdomain}/menu")->with('success', "Updated \"{$item->name}\".");
    }

    public function destroy(Restaurant $restaurant, MenuItem $item): RedirectResponse
    {
        $name = $item->name;
        $item->delete();

        return back()->with('success', "Deleted \"{$name}\".");
    }

    public function reorder(MenuItemReorderRequest $request, Restaurant $restaurant): Response
    {
        $categoryId = (int) $request->validated('category_id');
        $ids = $request->validated('ids');

        $allInCategory = MenuItem::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('menu_category_id', $categoryId)
            ->whereIn('id', $ids)
            ->count();

        if ($allInCategory !== count($ids)) {
            throw ValidationException::withMessages([
                'ids' => 'All items must belong to the specified category.',
            ]);
        }

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $index => $id) {
                MenuItem::where('id', $id)->update(['position' => $index]);
            }
        });

        return response()->noContent();
    }

    /**
     * @param  array<int, array<string, mixed>>  $modifiers
     */
    protected function syncModifiers(MenuItem $item, array $modifiers): void
    {
        $keepIds = [];

        foreach (array_values($modifiers) as $index => $mod) {
            $attrs = [
                'menu_item_id' => $item->id,
                'name' => $mod['name'],
                'group_label' => $mod['group_label'] ?? null,
                'price_delta_cents' => (int) ($mod['price_delta_cents'] ?? 0),
                'is_default' => (bool) ($mod['is_default'] ?? false),
                'position' => $index,
            ];

            if (! empty($mod['id'])) {
                $existing = MenuItemModifier::where('menu_item_id', $item->id)
                    ->where('id', $mod['id'])
                    ->first();
                if ($existing) {
                    $existing->update($attrs);
                    $keepIds[] = $existing->id;

                    continue;
                }
            }

            $created = MenuItemModifier::create($attrs);
            $keepIds[] = $created->id;
        }

        MenuItemModifier::where('menu_item_id', $item->id)
            ->whereNotIn('id', $keepIds ?: [0])
            ->delete();
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

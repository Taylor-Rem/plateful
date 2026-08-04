<?php

namespace App\Http\Controllers\Storefront\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MenuCategoryReorderRequest;
use App\Http\Requests\Admin\MenuCategoryStoreRequest;
use App\Http\Requests\Admin\MenuCategoryUpdateRequest;
use App\Models\MenuCategory;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Category management from the storefront's inline edit mode. Mirrors the
 * admin-host MenuCategoryController but resolves the restaurant from the
 * tenant host rather than a route parameter.
 */
class MenuCategoryController extends Controller
{
    public function store(MenuCategoryStoreRequest $request, CurrentTenant $tenant): RedirectResponse
    {
        $restaurant = $tenant->get();
        $this->authorize('manage', $restaurant);

        $validated = $request->validated();

        $slug = $this->ensureUniqueSlug($restaurant->id, $validated['slug'] ?? Str::slug($validated['name']));

        $position = (int) ($restaurant->menuCategories()->max('position') ?? -1) + 1;

        MenuCategory::create([
            'restaurant_id' => $restaurant->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'slug' => $slug,
            'position' => $position,
            'is_active' => true,
        ]);

        return back()->with('success', 'Category created.');
    }

    public function update(
        MenuCategoryUpdateRequest $request,
        CurrentTenant $tenant,
        MenuCategory $category,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $this->authorize('manage', $restaurant);
        abort_if($category->restaurant_id !== $restaurant->id, 404);

        $validated = $request->validated();

        $category->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'slug' => $validated['slug'] ?? $category->slug,
        ]);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(CurrentTenant $tenant, MenuCategory $category): RedirectResponse
    {
        $restaurant = $tenant->get();
        $this->authorize('manage', $restaurant);
        abort_if($category->restaurant_id !== $restaurant->id, 404);

        $count = $category->items()->count();

        if ($count > 0) {
            throw ValidationException::withMessages([
                'category' => "This category has {$count} items. Move or delete them first.",
            ]);
        }

        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    public function reorder(MenuCategoryReorderRequest $request, CurrentTenant $tenant): RedirectResponse
    {
        $restaurant = $tenant->get();
        $this->authorize('manage', $restaurant);

        $ids = $request->validated('ids');

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $index => $id) {
                MenuCategory::where('id', $id)->update(['position' => $index]);
            }
        });

        return back()->with('success', 'Order updated.');
    }

    protected function ensureUniqueSlug(int $restaurantId, string $base): string
    {
        $slug = $base;
        $i = 2;

        while (MenuCategory::withoutTenantScope()
            ->where('restaurant_id', $restaurantId)
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}

<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MenuCategoryReorderRequest;
use App\Http\Requests\Admin\MenuCategoryStoreRequest;
use App\Http\Requests\Admin\MenuCategoryUpdateRequest;
use App\Models\MenuCategory;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MenuCategoryController extends Controller
{
    public function store(MenuCategoryStoreRequest $request, Restaurant $restaurant): RedirectResponse
    {
        $validated = $request->validated();

        $slug = $validated['slug'] ?? Str::slug($validated['name']);

        $slug = $this->ensureUniqueSlug($restaurant->id, $slug);

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

    public function update(MenuCategoryUpdateRequest $request, Restaurant $restaurant, MenuCategory $category): RedirectResponse
    {
        $validated = $request->validated();

        $category->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'slug' => $validated['slug'] ?? $category->slug,
        ]);

        return back()->with('success', 'Category updated.');
    }

    public function destroy(Restaurant $restaurant, MenuCategory $category): RedirectResponse
    {
        $count = $category->items()->count();

        if ($count > 0) {
            throw ValidationException::withMessages([
                'category' => "This category has {$count} items. Move or delete them first.",
            ]);
        }

        $category->delete();

        return back()->with('success', 'Category deleted.');
    }

    public function reorder(MenuCategoryReorderRequest $request, Restaurant $restaurant): Response
    {
        $ids = $request->validated('ids');

        DB::transaction(function () use ($ids): void {
            foreach ($ids as $index => $id) {
                MenuCategory::where('id', $id)->update(['position' => $index]);
            }
        });

        return response()->noContent();
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

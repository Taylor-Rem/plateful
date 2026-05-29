<?php

namespace App\Http\Controllers\Storefront\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PhotoReorderRequest;
use App\Http\Requests\Admin\PhotoStoreRequest;
use App\Http\Requests\Admin\PhotoUpdateRequest;
use App\Models\RestaurantPhoto;
use App\Services\RestaurantImageService;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class PhotoController extends Controller
{
    public function store(
        PhotoStoreRequest $request,
        CurrentTenant $tenant,
        RestaurantImageService $images,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $this->authorize('updateSite', $restaurant);

        $validated = $request->validated();

        $position = (int) (RestaurantPhoto::query()
            ->where('restaurant_id', $restaurant->id)
            ->max('position') ?? -1) + 1;

        DB::transaction(function () use ($restaurant, $validated, $request, $images, $position): void {
            $photo = RestaurantPhoto::create([
                'restaurant_id' => $restaurant->id,
                'caption' => $validated['caption'] ?? null,
                'position' => $position,
            ]);

            $photo->image_path = $images->storeGalleryPhoto($photo, $request->file('image'));
            $photo->save();
        });

        return back()->with('success', 'Photo added.');
    }

    public function update(
        PhotoUpdateRequest $request,
        RestaurantPhoto $photo,
        CurrentTenant $tenant,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $this->authorize('updateSite', $restaurant);
        abort_if($photo->restaurant_id !== $restaurant->id, 404);

        $photo->update([
            'caption' => $request->validated()['caption'] ?? null,
        ]);

        return back()->with('success', 'Photo updated.');
    }

    public function reorder(
        PhotoReorderRequest $request,
        CurrentTenant $tenant,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $this->authorize('updateSite', $restaurant);

        $ids = $request->validated()['ids'];

        // Limit reorder to photos owned by this tenant. BelongsToTenant
        // global scope guarantees we only touch our own rows.
        $owned = RestaurantPhoto::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        DB::transaction(function () use ($ids, $owned): void {
            $position = 0;
            foreach ($ids as $id) {
                if (! in_array((int) $id, $owned, true)) {
                    continue;
                }
                RestaurantPhoto::query()
                    ->where('id', $id)
                    ->update(['position' => $position++]);
            }
        });

        return back()->with('success', 'Order updated.');
    }

    public function destroy(
        RestaurantPhoto $photo,
        CurrentTenant $tenant,
        RestaurantImageService $images,
    ): RedirectResponse {
        $restaurant = $tenant->get();
        $this->authorize('updateSite', $restaurant);
        abort_if($photo->restaurant_id !== $restaurant->id, 404);

        DB::transaction(function () use ($photo, $images): void {
            $images->deleteDirectoryForGalleryPhoto($photo);
            $photo->delete();
        });

        return back()->with('success', 'Photo removed.');
    }
}

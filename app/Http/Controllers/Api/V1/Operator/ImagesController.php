<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Data\MenuItemData;
use App\Data\RestaurantImagesData;
use App\Data\RestaurantPhotoData;
use App\Enums\RestaurantImageKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operator\ImageUploadRequest;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\RestaurantPhoto;
use App\Support\Operator\OperatorImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Image uploads for a restaurant: a menu item's photo, the logo / hero /
 * about slots, and the gallery. Same conversion pipeline as the web admin.
 */
class ImagesController extends Controller
{
    public function __construct(protected OperatorImages $images) {}

    public function setMenuItemImage(ImageUploadRequest $request, Restaurant $restaurant, MenuItem $menuItem): JsonResponse
    {
        $this->images->setMenuItemImage($menuItem, $request->uploadedImage($this->images));

        return response()->json(['data' => MenuItemData::fromModel($menuItem->refresh())]);
    }

    public function removeMenuItemImage(Restaurant $restaurant, MenuItem $menuItem): JsonResponse
    {
        $this->images->removeMenuItemImage($menuItem);

        return response()->json(['data' => MenuItemData::fromModel($menuItem->refresh())]);
    }

    public function setRestaurantImage(ImageUploadRequest $request, Restaurant $restaurant, RestaurantImageKind $kind): JsonResponse
    {
        $this->images->setRestaurantImage($restaurant, $kind, $request->uploadedImage($this->images));

        return response()->json(['data' => RestaurantImagesData::fromModel($restaurant->refresh())]);
    }

    public function removeRestaurantImage(Restaurant $restaurant, RestaurantImageKind $kind): JsonResponse
    {
        $this->images->removeRestaurantImage($restaurant, $kind);

        return response()->json(['data' => RestaurantImagesData::fromModel($restaurant->refresh())]);
    }

    public function photos(Restaurant $restaurant): JsonResponse
    {
        return response()->json([
            'data' => $restaurant->photos()->get()
                ->map(fn (RestaurantPhoto $photo) => RestaurantPhotoData::fromModel($photo))
                ->all(),
        ]);
    }

    public function storePhoto(ImageUploadRequest $request, Restaurant $restaurant): JsonResponse
    {
        $photo = $this->images->addGalleryPhoto(
            $restaurant,
            $request->uploadedImage($this->images),
            $request->input('caption'),
        );

        return response()->json(['data' => RestaurantPhotoData::fromModel($photo)], 201);
    }

    public function destroyPhoto(Restaurant $restaurant, RestaurantPhoto $photo): Response
    {
        $this->images->removeGalleryPhoto($photo);

        return response()->noContent();
    }
}

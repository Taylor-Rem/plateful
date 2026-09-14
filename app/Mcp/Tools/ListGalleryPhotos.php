<?php

namespace App\Mcp\Tools;

use App\Data\RestaurantPhotoData;
use App\Enums\ApiKeyScope;
use App\Models\RestaurantPhoto;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The restaurant\'s gallery photos in display order, with ids (for remove-image), captions and image URLs.')]
class ListGalleryPhotos extends OperatorTool
{
    public function handle(Request $request): Response
    {
        $restaurant = $this->restaurant($request, ApiKeyScope::RestaurantsRead);

        return $this->json([
            'data' => $restaurant->photos()->get()
                ->map(fn (RestaurantPhoto $photo) => RestaurantPhotoData::fromModel($photo)->toArray())
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['restaurant' => $this->restaurantArgument($schema)];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Data\MenuItemData;
use App\Data\RestaurantImagesData;
use App\Enums\ApiKeyScope;
use App\Enums\RestaurantImageKind;
use App\Models\MenuItem;
use App\Models\RestaurantPhoto;
use App\Support\Operator\OperatorImages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsDestructive]
#[IsIdempotent]
#[Description('Remove an image: a menu item\'s photo (target menu_item + menu_item_id), the restaurant\'s logo, hero or about image (target logo|hero|about), or one gallery photo (target gallery + photo_id). The files are deleted from storage. Confirm with the person first.')]
class RemoveImage extends OperatorTool
{
    public function __construct(protected OperatorImages $images) {}

    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'target' => ['required', Rule::in(['menu_item', 'logo', 'hero', 'about', 'gallery'])],
            'menu_item_id' => ['required_if:target,menu_item', 'nullable', 'integer'],
            'photo_id' => ['required_if:target,gallery', 'nullable', 'integer'],
        ]);

        $scope = $input['target'] === 'menu_item' ? ApiKeyScope::MenuWrite : ApiKeyScope::RestaurantsWrite;
        $restaurant = $this->restaurant($request, $scope);

        if ($input['target'] === 'menu_item') {
            $item = MenuItem::query()->whereKey($input['menu_item_id'])->first();

            if ($item === null) {
                return Response::error("No menu item [{$input['menu_item_id']}] at {$restaurant->name}.");
            }

            return $this->json(MenuItemData::fromModel($this->images->removeMenuItemImage($item)->refresh()));
        }

        if ($input['target'] === 'gallery') {
            $photo = RestaurantPhoto::query()->whereKey($input['photo_id'])->first();

            if ($photo === null) {
                return Response::error("No gallery photo [{$input['photo_id']}] at {$restaurant->name}.");
            }

            $this->images->removeGalleryPhoto($photo);

            return $this->json(['removed' => true, 'photoId' => (int) $input['photo_id']]);
        }

        $kind = RestaurantImageKind::from($input['target']);

        return $this->json(RestaurantImagesData::fromModel($this->images->removeRestaurantImage($restaurant, $kind)->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $this->restaurantArgument($schema),
            'target' => $schema->string()->enum(['menu_item', 'logo', 'hero', 'about', 'gallery'])->required(),
            'menu_item_id' => $schema->integer()->description('Required when target is menu_item.'),
            'photo_id' => $schema->integer()->description('Required when target is gallery; from list-gallery-photos.'),
        ];
    }
}

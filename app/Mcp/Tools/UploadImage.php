<?php

namespace App\Mcp\Tools;

use App\Data\MenuItemData;
use App\Data\RestaurantImagesData;
use App\Data\RestaurantPhotoData;
use App\Enums\ApiKeyScope;
use App\Enums\RestaurantImageKind;
use App\Exceptions\InvalidImageException;
use App\Mcp\Tools\Concerns\ResolvesImageSource;
use App\Models\MenuItem;
use App\Support\Operator\OperatorImages;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Upload an image: a menu item\'s photo (target menu_item + menu_item_id), the restaurant\'s logo, hero or about image (target logo|hero|about, replacing the current one), or a new gallery photo (target gallery, optional caption). Give the image as source_url (an https URL the server fetches) or image_base64. JPEG, PNG, WebP up to 8 MB; converted to webp variants like the web admin. Changes the live storefront; confirm with the person first.')]
class UploadImage extends OperatorTool
{
    use ResolvesImageSource;

    public function __construct(protected OperatorImages $images) {}

    public function handle(Request $request): Response
    {
        $input = $request->validate([
            'target' => ['required', Rule::in(['menu_item', 'logo', 'hero', 'about', 'gallery'])],
            'menu_item_id' => ['required_if:target,menu_item', 'nullable', 'integer'],
            'caption' => ['nullable', 'string', 'max:255'],
            'source_url' => ['nullable', 'string'],
            'image_base64' => ['nullable', 'string'],
            'filename' => ['nullable', 'string', 'max:100'],
        ]);

        $scope = $input['target'] === 'menu_item' ? ApiKeyScope::MenuWrite : ApiKeyScope::RestaurantsWrite;
        $restaurant = $this->restaurant($request, $scope);

        try {
            $file = $this->imageFromInput($this->images, $input);

            if ($input['target'] === 'menu_item') {
                $item = MenuItem::query()->whereKey($input['menu_item_id'])->first();

                if ($item === null) {
                    return Response::error("No menu item [{$input['menu_item_id']}] at {$restaurant->name}.");
                }

                return $this->json(MenuItemData::fromModel($this->images->setMenuItemImage($item, $file)->refresh()));
            }

            if ($input['target'] === 'gallery') {
                return $this->json(RestaurantPhotoData::fromModel($this->images->addGalleryPhoto($restaurant, $file, $input['caption'] ?? null)));
            }

            $kind = RestaurantImageKind::from($input['target']);

            return $this->json(RestaurantImagesData::fromModel($this->images->setRestaurantImage($restaurant, $kind, $file)->refresh()));
        } catch (InvalidImageException $e) {
            return Response::error($e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $this->restaurantArgument($schema),
            'target' => $schema->string()->enum(['menu_item', 'logo', 'hero', 'about', 'gallery'])->required(),
            'menu_item_id' => $schema->integer()->description('Required when target is menu_item; from get-menu.'),
            'caption' => $schema->string()->max(255)->description('Gallery only.'),
            'source_url' => $schema->string()->description('An http(s) URL of the image; the server fetches it.'),
            'image_base64' => $schema->string()->description('The image bytes as base64 (bare or a data: URI). Use when there is no URL.'),
            'filename' => $schema->string()->description('Optional name for a base64 image, e.g. margherita.jpg.'),
        ];
    }
}

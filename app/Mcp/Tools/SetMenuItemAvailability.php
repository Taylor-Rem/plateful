<?php

namespace App\Mcp\Tools;

use App\Data\MenuItemData;
use App\Enums\ApiKeyScope;
use App\Models\MenuItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Mark a menu item available or sold out (86 it). Takes effect on the storefront and in the app immediately; confirm with the person first.')]
class SetMenuItemAvailability extends OperatorTool
{
    public function handle(Request $request): Response
    {
        $restaurant = $this->restaurant($request, ApiKeyScope::MenuWrite);

        $input = $request->validate([
            'menu_item_id' => ['required', 'integer'],
            'is_available' => ['required', 'boolean'],
        ]);

        $item = MenuItem::query()->whereKey($input['menu_item_id'])->first();

        if ($item === null) {
            return Response::error("No menu item [{$input['menu_item_id']}] at {$restaurant->name}.");
        }

        $item->update(['is_available' => (bool) $input['is_available']]);

        return $this->json(MenuItemData::fromModel($item->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $this->restaurantArgument($schema),
            'menu_item_id' => $schema->integer()->description('From get-menu.')->required(),
            'is_available' => $schema->boolean()->required(),
        ];
    }
}

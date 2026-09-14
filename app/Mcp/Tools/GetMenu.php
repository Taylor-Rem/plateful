<?php

namespace App\Mcp\Tools;

use App\Enums\ApiKeyScope;
use App\Support\Menus\StorefrontMenuQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The full menu tree: categories, items with prices and availability, option groups, ingredients and default selections. Hidden categories and unavailable items are included unless include_hidden is false.')]
class GetMenu extends OperatorTool
{
    public function __construct(protected StorefrontMenuQuery $menu) {}

    public function handle(Request $request): Response
    {
        $restaurant = $this->restaurant($request, ApiKeyScope::MenuRead);

        $input = $request->validate(['include_hidden' => ['nullable', 'boolean']]);

        return $this->json([
            'data' => array_map(
                fn ($category) => $category->toArray(),
                $this->menu->categoryData($restaurant, includeHidden: (bool) ($input['include_hidden'] ?? true)),
            ),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $this->restaurantArgument($schema),
            'include_hidden' => $schema->boolean()->default(true)->description('False returns exactly what a customer sees.'),
        ];
    }
}

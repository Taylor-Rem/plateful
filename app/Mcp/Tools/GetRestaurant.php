<?php

namespace App\Mcp\Tools;

use App\Data\RestaurantData;
use App\Enums\ApiKeyScope;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('A restaurant\'s full profile: contact details, address, hours by weekday, fees, delivery and Stripe readiness, branding.')]
class GetRestaurant extends OperatorTool
{
    public function handle(Request $request): Response
    {
        $restaurant = $this->restaurant($request, ApiKeyScope::RestaurantsRead);
        $restaurant->load(['hours', 'photos']);

        return $this->json(RestaurantData::fromModel($restaurant));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['restaurant' => $this->restaurantArgument($schema)];
    }
}

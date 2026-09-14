<?php

namespace App\Mcp\Tools;

use App\Data\OrderData;
use App\Enums\ApiKeyScope;
use App\Models\Order;
use App\Support\Operator\OperatorOrders;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The live kitchen board: every pending, confirmed, preparing and ready order, oldest first, with line items. Pass `since` (the previous call\'s asOf) to get only what changed.')]
class KitchenBoard extends OperatorTool
{
    public function __construct(protected OperatorOrders $orders) {}

    public function handle(Request $request): Response
    {
        $restaurant = $this->restaurant($request, ApiKeyScope::OrdersRead);

        $input = $request->validate(['since' => ['nullable', 'date']]);

        return $this->json([
            'data' => $this->orders->board($restaurant, $input['since'] ?? null)
                ->map(fn (Order $o) => OrderData::fromModel($o)->toArray())
                ->all(),
            'meta' => ['asOf' => now()->toIso8601String()],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $this->restaurantArgument($schema),
            'since' => $schema->string()->description('Only orders updated after this instant.'),
        ];
    }
}

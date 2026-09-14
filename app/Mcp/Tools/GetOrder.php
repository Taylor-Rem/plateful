<?php

namespace App\Mcp\Tools;

use App\Data\OperatorOrderData;
use App\Enums\ApiKeyScope;
use App\Support\Operator\OperatorOrders;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('One order in full: line items with selections, totals, customer contact, delivery assignment, payment state, POS push state, and the status timeline (newest event first).')]
class GetOrder extends OperatorTool
{
    public function __construct(protected OperatorOrders $orders) {}

    public function handle(Request $request): Response
    {
        $restaurant = $this->restaurant($request, ApiKeyScope::OrdersRead);

        $input = $request->validate(['order' => ['required', 'string']]);

        $order = $this->orders->find($restaurant, $input['order']);

        if ($order === null) {
            return Response::error("No order [{$input['order']}] at {$restaurant->name}.");
        }

        return $this->json(OperatorOrderData::fromModel($order));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $this->restaurantArgument($schema),
            'order' => $schema->string()->description('The order number (e.g. ABC-12345) or numeric id.')->required(),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Data\OperatorOrderData;
use App\Enums\ApiKeyScope;
use App\Enums\OrderStatus;
use App\Exceptions\InvalidOrderTransitionException;
use App\Services\OrderTransition;
use App\Support\Operator\OperatorOrders;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Move an order to its next status (confirmed, preparing, ready, completed, or cancelled). Runs the same pipeline as the admin console: emails, refunds on cancel, loyalty on completion, courier dispatch. Refuses illegal moves. This changes a live order; confirm with the person first.')]
class TransitionOrder extends OperatorTool
{
    public function __construct(protected OperatorOrders $orders, protected OrderTransition $transition) {}

    public function handle(Request $request): Response
    {
        $restaurant = $this->restaurant($request, ApiKeyScope::OrdersWrite);

        $input = $request->validate([
            'order' => ['required', 'string'],
            'to_status' => ['required', 'string', Rule::in(['confirmed', 'preparing', 'ready', 'completed', 'cancelled'])],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $order = $this->orders->find($restaurant, $input['order']);

        if ($order === null) {
            return Response::error("No order [{$input['order']}] at {$restaurant->name}.");
        }

        try {
            $order = $this->transition->apply(
                $order,
                OrderStatus::from($input['to_status']),
                $this->actor($request)->auditUser(),
                $input['note'] ?? null,
            );
        } catch (InvalidOrderTransitionException $e) {
            return Response::error($e->getMessage());
        }

        return $this->json(OperatorOrderData::fromModel($order->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $this->restaurantArgument($schema),
            'order' => $schema->string()->description('The order number or numeric id.')->required(),
            'to_status' => $schema->string()
                ->enum(['confirmed', 'preparing', 'ready', 'completed', 'cancelled'])
                ->required(),
            'note' => $schema->string()->max(1000)->description('Recorded on the timeline; sent to the customer on cancellation.'),
        ];
    }
}

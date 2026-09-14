<?php

namespace App\Mcp\Tools;

use App\Data\OrderSummaryData;
use App\Data\PaginationMetaData;
use App\Enums\ApiKeyScope;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Support\Operator\OperatorOrders;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Page through a restaurant\'s orders, newest first, with optional status, search, and date filters. Returns summaries; use get-order for line items, payment state and the status timeline. The meta includes a count of orders per status.')]
class ListOrders extends OperatorTool
{
    public function __construct(protected OperatorOrders $orders) {}

    public function handle(Request $request): Response
    {
        $restaurant = $this->restaurant($request, ApiKeyScope::OrdersRead);

        $input = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => ['string'],
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'since' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $this->orders->paginate(
            $restaurant,
            [
                'status' => $input['status'] ?? [],
                'search' => $input['search'] ?? '',
                'from' => $input['from'] ?? null,
                'to' => $input['to'] ?? null,
                'since' => $input['since'] ?? null,
            ],
            (int) ($input['per_page'] ?? 25),
            (int) ($input['page'] ?? 1),
        );

        return $this->json([
            'data' => $paginator->getCollection()
                ->map(fn (Order $o) => OrderSummaryData::fromModel($o)->toArray())
                ->all(),
            'meta' => [
                ...PaginationMetaData::fromPaginator($paginator)->toArray(),
                'statusCounts' => $this->orders->statusCounts($restaurant),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $this->restaurantArgument($schema),
            'status' => $schema->array()
                ->items($schema->string()->enum(array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases())))
                ->description('Only these statuses. Omit for all.'),
            'search' => $schema->string()->description('Matches order number, customer name or email.'),
            'from' => $schema->string()->description('Placed at or after this date/time, in the restaurant\'s timezone.'),
            'to' => $schema->string()->description('Placed at or before this date/time, in the restaurant\'s timezone.'),
            'since' => $schema->string()->description('Only orders updated after this instant (poll cursor).'),
            'page' => $schema->integer()->min(1)->default(1),
            'per_page' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }
}

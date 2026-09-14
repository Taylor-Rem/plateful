<?php

namespace App\Mcp\Tools;

use App\Data\RestaurantEarningsData;
use App\Enums\ApiKeyScope;
use App\Support\Platform\EarningsQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('What each restaurant generated in a month: orders, food subtotal, gross application fee, Plateful\'s true commission against the restaurant\'s monthly cap, delivery margin, and the ledger total for cross-checking. Every restaurant appears, busiest first. Platform-only.')]
class EarningsByRestaurant extends OperatorTool
{
    public function __construct(protected EarningsQuery $earnings) {}

    public function handle(Request $request): Response
    {
        $this->platform($request, ApiKeyScope::PlatformRead);

        $input = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);
        $month = $this->earnings->month($input['month'] ?? null);
        $rows = $this->earnings->restaurantBreakdown($month);

        return $this->json([
            'data' => array_map(fn (RestaurantEarningsData $r) => $r->toArray(), $rows),
            'meta' => [
                'month' => $month->format('Y-m'),
                'totalCommissionCents' => array_sum(array_map(fn (RestaurantEarningsData $r) => $r->commissionCents, $rows)),
                'totalDeliveryMarginCents' => array_sum(array_map(fn (RestaurantEarningsData $r) => $r->deliveryMarginCents, $rows)),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['month' => $this->monthArgument($schema)];
    }
}

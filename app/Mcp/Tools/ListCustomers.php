<?php

namespace App\Mcp\Tools;

use App\Data\CustomerData;
use App\Data\PaginationMetaData;
use App\Enums\ApiKeyScope;
use App\Models\RestaurantCustomer;
use App\Support\Customers\CustomersQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('A restaurant\'s online-ordering customers with order counts, spend, loyalty balance and marketing opt-in. Sortable and searchable; excludes deleted accounts.')]
class ListCustomers extends OperatorTool
{
    public function __construct(protected CustomersQuery $customers) {}

    public function handle(Request $request): Response
    {
        $restaurant = $this->restaurant($request, ApiKeyScope::CustomersRead);

        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'ordered' => ['nullable', Rule::in([30, 90])],
            'marketing' => ['nullable', Rule::in(['opted_in'])],
            'sort' => ['nullable', Rule::in(array_keys(CustomersQuery::SORTABLE))],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        [$sort, $dir] = $this->customers->sort($input['sort'] ?? null, $input['dir'] ?? null);

        $paginator = $this->customers
            ->build($restaurant, [
                'search' => trim((string) ($input['search'] ?? '')),
                'ordered' => isset($input['ordered']) ? (int) $input['ordered'] : null,
                'marketing' => $input['marketing'] ?? null,
            ])
            ->orderBy(CustomersQuery::SORTABLE[$sort], $dir)
            ->orderBy('restaurant_customer.id', 'desc')
            ->paginate((int) ($input['per_page'] ?? 25), ['*'], 'page', (int) ($input['page'] ?? 1));

        return $this->json([
            'data' => $paginator->getCollection()
                ->map(fn (RestaurantCustomer $pivot) => CustomerData::fromModel($pivot)->toArray())
                ->all(),
            'meta' => PaginationMetaData::fromPaginator($paginator)->toArray(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $this->restaurantArgument($schema),
            'search' => $schema->string()->description('Matches name or email.'),
            'ordered' => $schema->integer()->enum([30, 90])->description('Only customers who ordered in the last N days.'),
            'marketing' => $schema->string()->enum(['opted_in'])->description('Only customers opted in to marketing email.'),
            'sort' => $schema->string()->enum(array_keys(CustomersQuery::SORTABLE))->default('last_ordered'),
            'dir' => $schema->string()->enum(['asc', 'desc'])->default('desc'),
            'page' => $schema->integer()->min(1)->default(1),
            'per_page' => $schema->integer()->min(1)->max(100)->default(25),
        ];
    }
}

<?php

namespace App\Mcp\Tools;

use App\Data\FeeDistributionData;
use App\Data\PaginationMetaData;
use App\Enums\ApiKeyScope;
use App\Enums\RevenueRole;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\Platform\EarningsQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The raw earnings ledger, newest first: one row per (order, person, role) slice of the retained fee. Filter by restaurant, person (id or email), order number, role, and a month or date range, to trace a payout total back to its orders. Refunded orders are hidden unless include_refunded is true. Platform-only.')]
class EarningsLedger extends OperatorTool
{
    public function __construct(protected EarningsQuery $earnings) {}

    public function handle(Request $request): Response
    {
        $this->platform($request, ApiKeyScope::PlatformRead);

        $input = $request->validate([
            'restaurant' => ['nullable', 'string'],
            'user' => ['nullable', 'string'],
            'order' => ['nullable', 'string'],
            'role' => ['nullable', Rule::enum(RevenueRole::class)],
            'month' => ['nullable', 'regex:/^\d{4}-\d{2}$/'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_refunded' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $restaurant = null;

        if (! empty($input['restaurant'])) {
            $restaurant = Restaurant::query()->where('subdomain', $input['restaurant'])->first();

            if ($restaurant === null) {
                return Response::error("No restaurant [{$input['restaurant']}]. Call list-restaurants for subdomains.");
            }
        }

        $user = null;

        if (! empty($input['user'])) {
            $user = User::withTrashed()
                ->when(is_numeric($input['user']), fn ($q) => $q->whereKey((int) $input['user']), fn ($q) => $q->where('email', $input['user']))
                ->first();

            if ($user === null) {
                return Response::error("No user [{$input['user']}].");
            }
        }

        $from = ! empty($input['from']) ? CarbonImmutable::parse($input['from']) : null;
        $to = ! empty($input['to']) ? CarbonImmutable::parse($input['to']) : null;

        if ($from === null && $to === null && ! empty($input['month'])) {
            $month = $this->earnings->month($input['month']);
            $from = $month->startOfMonth();
            $to = $month->endOfMonth();
        }

        $paginator = $this->earnings->ledger([
            'restaurant' => $restaurant,
            'user' => $user,
            'order' => $input['order'] ?? null,
            'role' => ! empty($input['role']) ? RevenueRole::from($input['role']) : null,
            'from' => $from,
            'to' => $to,
            'include_refunded' => (bool) ($input['include_refunded'] ?? false),
        ], (int) ($input['per_page'] ?? 50), (int) ($input['page'] ?? 1));

        return $this->json([
            'data' => array_map(fn (FeeDistributionData $d) => $d->toArray(), $this->earnings->ledgerData($paginator)),
            'meta' => [
                ...PaginationMetaData::fromPaginator($paginator)->toArray(),
                'pageTotalCents' => (int) collect($paginator->items())->sum('amount_cents'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'restaurant' => $schema->string()->description('Restaurant subdomain.'),
            'user' => $schema->string()->description('Earner: user id or email.'),
            'order' => $schema->string()->description('Order number, e.g. ABC-12345.'),
            'role' => $schema->string()->enum(array_map(fn (RevenueRole $r) => $r->value, RevenueRole::cases())),
            'month' => $this->monthArgument($schema),
            'from' => $schema->string()->description('Earned at or after this date/time (overrides month).'),
            'to' => $schema->string()->description('Earned at or before this date/time (overrides month).'),
            'include_refunded' => $schema->boolean()->default(false),
            'page' => $schema->integer()->min(1)->default(1),
            'per_page' => $schema->integer()->min(1)->max(200)->default(50),
        ];
    }
}

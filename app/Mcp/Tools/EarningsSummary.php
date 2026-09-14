<?php

namespace App\Mcp\Tools;

use App\Enums\ApiKeyScope;
use App\Support\Platform\EarningsQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('The monthly payout sheet: how much each person earned from the retained platform fee, by revenue role (founder, operator, recruiter, overseer, delivery margin), with the configured shares and the current founder/operator. Refunded orders are excluded. Platform-only.')]
class EarningsSummary extends OperatorTool
{
    public function __construct(protected EarningsQuery $earnings) {}

    public function handle(Request $request): Response
    {
        $this->platform($request, ApiKeyScope::PlatformRead);

        $input = $request->validate(['month' => ['nullable', 'regex:/^\d{4}-\d{2}$/']]);

        return $this->json($this->earnings->payoutSummary($this->earnings->month($input['month'] ?? null)));
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return ['month' => $this->monthArgument($schema)];
    }
}

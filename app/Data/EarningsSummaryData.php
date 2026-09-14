<?php

namespace App\Data;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The monthly payout sheet: who earned what from the retained platform fee.
 */
#[TypeScript]
class EarningsSummaryData extends Data
{
    /**
     * @param  array<string, float>  $shares  Configured RevenueRole => percent of the retained fee
     * @param  array{id: int, name: string}|null  $founder
     * @param  array{id: int, name: string}|null  $operator
     */
    public function __construct(
        /** `YYYY-MM`. */
        public string $month,
        public string $monthLabel,
        public int $totalCents,
        public array $shares,
        public ?array $founder,
        public ?array $operator,
        #[DataCollectionOf(EarnerData::class)]
        /** @var array<int, EarnerData> Highest earner first. */
        public array $earners,
    ) {}
}

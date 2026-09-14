<?php

namespace App\Http\Controllers\Api\V1\Operator\Platform;

use App\Data\PaginationMetaData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operator\Platform\EarningsLedgerRequest;
use App\Http\Requests\Api\V1\Operator\Platform\EarningsMonthRequest;
use App\Support\Platform\EarningsQuery;
use Illuminate\Http\JsonResponse;

/**
 * Platform earnings, read-only, behind `platform:read`: the payout sheet,
 * the per-restaurant breakdown, and the ledger rows behind either.
 */
class EarningsController extends Controller
{
    public function __construct(protected EarningsQuery $earnings) {}

    public function summary(EarningsMonthRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->earnings->payoutSummary($this->earnings->month($request->input('month'))),
        ]);
    }

    public function restaurants(EarningsMonthRequest $request): JsonResponse
    {
        $month = $this->earnings->month($request->input('month'));

        return response()->json([
            'data' => $this->earnings->restaurantBreakdown($month),
            'meta' => ['month' => $month->format('Y-m')],
        ]);
    }

    public function ledger(EarningsLedgerRequest $request): JsonResponse
    {
        $paginator = $this->earnings->ledger($request->filters(), $request->perPage(), $request->page());

        return response()->json([
            'data' => $this->earnings->ledgerData($paginator),
            'meta' => [
                ...PaginationMetaData::fromPaginator($paginator)->toArray(),
                'totalCents' => (int) collect($paginator->items())->sum('amount_cents'),
            ],
        ]);
    }
}

<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Platform\EarningsQuery;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EarningsController extends Controller
{
    public function __construct(protected EarningsQuery $earnings) {}

    /**
     * Monthly earnings report: how much each person earned from the platform
     * fee in the selected month, for direct-deposit payouts. The numbers come
     * from EarningsQuery, which the operator API and MCP tools share.
     */
    public function index(Request $request): Response
    {
        $month = $this->earnings->month($request->query('month'));
        $summary = $this->earnings->payoutSummary($month);

        return Inertia::render('Admin/SuperAdmin/Earnings', [
            'month' => $summary->month,
            'monthLabel' => $summary->monthLabel,
            'prevMonth' => $month->subMonth()->format('Y-m'),
            'nextMonth' => $month->addMonth()->format('Y-m'),
            'earners' => $summary->earners,
            'totalCents' => $summary->totalCents,
            'shares' => $summary->shares,
            'platformRoles' => [
                'founder' => $summary->founder,
                'operator' => $summary->operator,
            ],
            'assignableUsers' => User::query()
                ->where('is_super_admin', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->all(),
        ]);
    }
}

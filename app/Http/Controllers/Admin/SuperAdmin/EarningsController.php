<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Enums\RevenueRole;
use App\Http\Controllers\Controller;
use App\Models\FeeDistribution;
use App\Models\PlatformRoleHolder;
use App\Models\User;
use App\Services\RevenueSplitResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class EarningsController extends Controller
{
    /**
     * Monthly earnings report: how much each person earned from the platform
     * fee in the selected month, for direct-deposit payouts. Reads the
     * fee_distributions ledger; fully-refunded orders are excluded because
     * their application fee was reversed (nobody earned it).
     */
    public function index(Request $request): Response
    {
        $month = $this->selectedMonth($request->query('month'));
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $aggregates = FeeDistribution::query()
            ->whereBetween('earned_at', [$start, $end])
            ->whereHas('order', fn ($q) => $q->whereNull('refunded_at'))
            ->selectRaw('user_id, role, SUM(amount_cents) as amount_cents')
            ->groupBy('user_id', 'role')
            ->get();

        $names = User::query()
            ->whereIn('id', $aggregates->pluck('user_id')->filter()->unique())
            ->get(['id', 'name', 'email'])
            ->keyBy('id');

        $earners = [];
        foreach ($aggregates as $row) {
            $key = $row->user_id ?? 0;
            if (! isset($earners[$key])) {
                $user = $row->user_id ? $names->get($row->user_id) : null;
                $earners[$key] = [
                    'userId' => $row->user_id,
                    'name' => $user->name ?? 'Removed user',
                    'email' => $user->email ?? null,
                    'roles' => [],
                    'totalCents' => 0,
                ];
            }
            $earners[$key]['roles'][$row->role->value] = (int) $row->amount_cents;
            $earners[$key]['totalCents'] += (int) $row->amount_cents;
        }

        usort($earners, fn ($a, $b) => $b['totalCents'] <=> $a['totalCents']);

        $person = fn (?User $u) => $u ? ['id' => $u->id, 'name' => $u->name] : null;

        return Inertia::render('Admin/SuperAdmin/Earnings', [
            'month' => $start->format('Y-m'),
            'monthLabel' => $start->format('F Y'),
            'prevMonth' => $start->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $start->copy()->addMonth()->format('Y-m'),
            'earners' => array_values($earners),
            'totalCents' => array_sum(array_column($earners, 'totalCents')),
            'shares' => app(RevenueSplitResolver::class)->shares(),
            'platformRoles' => [
                'founder' => $person(PlatformRoleHolder::holder(RevenueRole::Founder)),
                'operator' => $person(PlatformRoleHolder::holder(RevenueRole::Operator)),
            ],
            'assignableUsers' => User::query()
                ->where('is_super_admin', true)
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])
                ->all(),
        ]);
    }

    private function selectedMonth(?string $month): Carbon
    {
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $parsed = Carbon::createFromFormat('Y-m-d', $month.'-01');
            if ($parsed !== false) {
                return $parsed;
            }
        }

        return Carbon::now()->startOfMonth();
    }
}

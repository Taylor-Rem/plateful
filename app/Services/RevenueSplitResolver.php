<?php

namespace App\Services;

use App\Enums\RevenueRole;
use App\Models\FeeDistribution;
use App\Models\Order;
use App\Models\PlatformRoleHolder;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Turns a restaurant's retained platform fee into per-role earning slices.
 *
 * Shares come from config('platform.revenue_shares') and are shares of
 * Plateful's take (they sum to 100). Each share resolves to a concrete user:
 * founder/operator are platform-wide holders; recruiter/overseer are per
 * restaurant, with an unassigned overseer (and any unresolvable slice) falling
 * back to the Operator, then the Founder. A person holding several roles simply
 * earns several slices — the report sums them.
 */
class RevenueSplitResolver
{
    /**
     * Compute how a fee amount splits, without persisting. Cents are allocated
     * by the largest-remainder method so the slices sum EXACTLY to $feeCents.
     *
     * @return array<int, array{role: RevenueRole, user: ?User, percent: float, amountCents: int}>
     */
    public function splitFor(Restaurant $restaurant, int $feeCents): array
    {
        $shares = $this->shares();

        $operator = PlatformRoleHolder::holder(RevenueRole::Operator);
        $founder = PlatformRoleHolder::holder(RevenueRole::Founder);

        $resolve = fn (RevenueRole $role): ?User => match ($role) {
            RevenueRole::Founder => $founder,
            RevenueRole::Operator => $operator,
            RevenueRole::Recruiter => $restaurant->recruiter,
            RevenueRole::Overseer => $restaurant->overseer ?? $operator,
        };

        // Build the paying slices: only roles with a positive share and a
        // user we can actually attribute to (with the operator→founder
        // fallback for anything left dangling).
        $slices = [];
        foreach ($shares as $role => $percent) {
            if ($percent <= 0) {
                continue;
            }

            $roleEnum = RevenueRole::from((string) $role);
            $user = $resolve($roleEnum) ?? $operator ?? $founder;
            if (! $user) {
                continue;
            }

            $slices[] = ['role' => $roleEnum, 'user' => $user, 'percent' => (float) $percent];
        }

        return $this->allocate($slices, $feeCents);
    }

    /**
     * Persist the split for a freshly paid order. Idempotent: a replayed
     * webhook/return won't double-write, thanks to the (order, user, role)
     * unique key. Nothing is written when the fee rounds to zero.
     */
    public function record(Order $order): void
    {
        // Split Plateful's TRUE revenue, never the Stripe gross: a delivery
        // order's application_fee_cents also carries DoorDash's courier
        // passthrough + tip (DoorDash plan §1.2), which is not Plateful's to
        // distribute. platform_commission_cents is the commission alone.
        $commissionCents = (int) $order->platform_commission_cents;
        $marginCents = (int) $order->delivery_margin_cents;

        if ($commissionCents <= 0 && $marginCents <= 0) {
            return;
        }

        $restaurant = $order->relationLoaded('restaurant')
            ? $order->getRelation('restaurant')
            : $order->restaurant()->first();

        if (! $restaurant) {
            return;
        }

        $earnedAt = $order->placed_at ?? Carbon::now();

        foreach ($this->splitFor($restaurant, $commissionCents) as $slice) {
            if ($slice['amountCents'] <= 0) {
                continue;
            }

            FeeDistribution::query()->firstOrCreate(
                [
                    'order_id' => $order->id,
                    'user_id' => $slice['user']?->id,
                    'role' => $slice['role']->value,
                ],
                [
                    'restaurant_id' => $restaurant->id,
                    'percent' => $slice['percent'],
                    'amount_cents' => $slice['amountCents'],
                    'earned_at' => $earnedAt,
                ],
            );
        }

        $this->recordDeliveryMargin($order, $restaurant, $marginCents, $earnedAt);
    }

    /**
     * Undo the earning slices for revenue that was refunded on a cancellation
     * (DoorDash plan Session 5). Commission and the delivery margin are reversed
     * independently — a delivery-only refund gives back the margin while the
     * food commission stays earned, and vice-versa.
     *
     * The rows are deleted rather than negated: the earnings report and the
     * monthly cap both read live totals, and a slice that was never really kept
     * should simply not be there. Called after the order's own
     * platform_commission_cents / delivery_margin_cents are zeroed, so the two
     * sources of truth stay in agreement.
     */
    public function reverse(Order $order, bool $commission, bool $margin): void
    {
        if (! $commission && ! $margin) {
            return;
        }

        $query = FeeDistribution::query()->where('order_id', $order->id);

        // When only one side is reversed, scope to it; when both are, delete
        // every slice for the order.
        if ($commission && ! $margin) {
            $query->where('role', '!=', RevenueRole::DeliveryMargin->value);
        } elseif ($margin && ! $commission) {
            $query->where('role', RevenueRole::DeliveryMargin->value);
        }

        $query->delete();
    }

    /**
     * Attribute the delivery margin (0.04×D) 100% to the founder, as its OWN
     * ledger role rather than the founder's commission slice — the
     * (order, user, role) unique key forbids two founder rows per order, and a
     * dedicated role keeps the margin splittable differently later. Dormant
     * until Session 4b populates delivery_margin_cents.
     */
    private function recordDeliveryMargin(Order $order, Restaurant $restaurant, int $marginCents, CarbonInterface $earnedAt): void
    {
        if ($marginCents <= 0) {
            return;
        }

        $founder = PlatformRoleHolder::holder(RevenueRole::Founder);
        if (! $founder) {
            return;
        }

        FeeDistribution::query()->firstOrCreate(
            [
                'order_id' => $order->id,
                'user_id' => $founder->id,
                'role' => RevenueRole::DeliveryMargin->value,
            ],
            [
                'restaurant_id' => $restaurant->id,
                'percent' => 100,
                'amount_cents' => $marginCents,
                'earned_at' => $earnedAt,
            ],
        );
    }

    /**
     * The configured role→percent shares, keyed by RevenueRole. Unknown keys
     * are ignored; the sum is asserted to be 100 by a test, not here.
     *
     * @return array<string, float> role value => percent
     */
    public function shares(): array
    {
        $out = [];
        foreach ((array) config('platform.revenue_shares', []) as $role => $percent) {
            if (RevenueRole::tryFrom((string) $role) !== null) {
                $out[$role] = (float) $percent;
            }
        }

        return $out;
    }

    /**
     * Largest-remainder allocation of $feeCents across weighted slices.
     *
     * @param  array<int, array{role: RevenueRole, user: ?User, percent: float}>  $slices
     * @return array<int, array{role: RevenueRole, user: ?User, percent: float, amountCents: int}>
     */
    private function allocate(array $slices, int $feeCents): array
    {
        $totalWeight = array_sum(array_column($slices, 'percent'));
        if ($slices === [] || $totalWeight <= 0) {
            return [];
        }

        $remainders = [];
        $allocated = 0;
        foreach ($slices as $i => $slice) {
            $exact = $feeCents * $slice['percent'] / $totalWeight;
            $floor = (int) floor($exact);
            $slices[$i]['amountCents'] = $floor;
            $remainders[$i] = $exact - $floor;
            $allocated += $floor;
        }

        // Hand out the leftover cents to the largest fractional remainders.
        $leftover = $feeCents - $allocated;
        arsort($remainders);
        foreach (array_keys($remainders) as $i) {
            if ($leftover <= 0) {
                break;
            }
            $slices[$i]['amountCents']++;
            $leftover--;
        }

        return $slices;
    }
}

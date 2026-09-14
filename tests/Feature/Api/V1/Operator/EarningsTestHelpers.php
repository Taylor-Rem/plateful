<?php

use App\Enums\RevenueRole;
use App\Models\FeeDistribution;
use App\Models\PlatformRoleHolder;
use App\Models\Restaurant;
use App\Models\User;

require_once __DIR__.'/../../../Admin/AdminOrderTestHelpers.php';

if (! function_exists('earningsFixture')) {
    /**
     * Two restaurants, one month of ledger rows, one refunded order that must
     * never count.
     *
     * @return array{taylor: User, ben: User, marcos: Restaurant, luigis: Restaurant}
     */
    function earningsFixture(): array
    {
        $taylor = User::factory()->create(['name' => 'Taylor', 'email' => 'taylor@example.test']);
        $ben = User::factory()->create(['name' => 'Ben', 'email' => 'ben@example.test']);
        PlatformRoleHolder::assign(RevenueRole::Founder, $taylor);
        PlatformRoleHolder::assign(RevenueRole::Operator, $taylor);

        $marcos = adminOrderRestaurant('marcos');
        $marcos->forceFill(['name' => 'Marcos', 'overseer_id' => $ben->id, 'commission_monthly_cap_cents' => 39900])->save();
        $luigis = adminOrderRestaurant('luigis');
        $luigis->forceFill(['name' => 'Luigis'])->save();

        $slice = function (Restaurant $r, User $u, RevenueRole $role, int $cents, string $at, array $order = []) {
            $o = makeOrder($r, ['placed_at' => $at, 'platform_commission_cents' => 0, ...$order]);

            return FeeDistribution::factory()->create([
                'order_id' => $o->id, 'restaurant_id' => $r->id, 'user_id' => $u->id,
                'role' => $role, 'percent' => $role === RevenueRole::Founder ? 10 : 90,
                'amount_cents' => $cents, 'earned_at' => $at,
            ]);
        };

        // September: Marcos $50 fee (Ben 45 / Taylor 5), Luigis $20 fee (Taylor 2 + 18 as fallback overseer).
        $slice($marcos, $ben, RevenueRole::Overseer, 4500, '2026-09-02 10:00:00', ['number' => 'MAR-00001', 'subtotal_cents' => 125000, 'platform_commission_cents' => 5000, 'application_fee_cents' => 5000]);
        $slice($marcos, $taylor, RevenueRole::Founder, 500, '2026-09-02 10:00:00', ['number' => 'MAR-00002']);
        $slice($luigis, $taylor, RevenueRole::Founder, 200, '2026-09-05 10:00:00', ['number' => 'LUI-00001', 'subtotal_cents' => 50000, 'platform_commission_cents' => 2000, 'application_fee_cents' => 2000]);
        $slice($luigis, $taylor, RevenueRole::Overseer, 1800, '2026-09-05 10:00:00', ['number' => 'LUI-00002']);
        // A refunded September order: its slices exist but must be excluded.
        $slice($marcos, $ben, RevenueRole::Overseer, 9000, '2026-09-08 10:00:00', ['number' => 'MAR-REFND', 'refunded_at' => '2026-09-09 10:00:00', 'platform_commission_cents' => 0]);
        // August: outside the month.
        $slice($marcos, $ben, RevenueRole::Overseer, 7000, '2026-08-20 10:00:00', ['number' => 'MAR-AUG01', 'platform_commission_cents' => 7777]);

        return compact('taylor', 'ben', 'marcos', 'luigis');
    }
}

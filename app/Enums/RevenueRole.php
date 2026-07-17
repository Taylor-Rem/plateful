<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Roles that earn a share of the platform fee Plateful retains from a
 * restaurant. Distinct from {@see RestaurantRole}, which governs admin-panel
 * access. A person can hold several of these at once (their shares stack).
 *
 * - Founder / Operator are PLATFORM-WIDE singletons (see PlatformRoleHolder):
 *   one person is the Founder, one is the Operator, across all restaurants.
 * - Recruiter / Overseer are assigned PER RESTAURANT (nullable columns on
 *   `restaurants`); an unassigned Overseer falls back to the Operator.
 */
#[TypeScript]
enum RevenueRole: string
{
    case Founder = 'founder';
    case Operator = 'operator';
    case Recruiter = 'recruiter';
    case Overseer = 'overseer';

    /**
     * The delivery margin (0.04×D) Plateful nets on a third-party delivery.
     * Attributed 100% to the founder for now, but as its OWN ledger role rather
     * than the founder's commission slice — the (order, user, role) unique key
     * forbids two founder rows per order, and keeping it separate lets it be
     * split differently later (e.g. a `platform.delivery_margin_shares` config)
     * without disturbing the commission split. Not a share of config
     * `revenue_shares`; populated only from Session 4b onward.
     */
    case DeliveryMargin = 'delivery_margin';

    public function label(): string
    {
        return match ($this) {
            self::Founder => 'Founder',
            self::Operator => 'Operator',
            self::Recruiter => 'Recruiter',
            self::Overseer => 'Overseer',
            self::DeliveryMargin => 'Delivery margin',
        };
    }

    /**
     * The two roles held globally by a single person (via PlatformRoleHolder),
     * rather than assigned per restaurant.
     *
     * @return array<int, self>
     */
    public static function platformWide(): array
    {
        return [self::Founder, self::Operator];
    }
}

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

    public function label(): string
    {
        return match ($this) {
            self::Founder => 'Founder',
            self::Operator => 'Operator',
            self::Recruiter => 'Recruiter',
            self::Overseer => 'Overseer',
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

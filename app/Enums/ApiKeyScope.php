<?php

namespace App\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What an API key (or, mapped from their role, a signed-in operator) may do
 * through the operator API and the MCP server. `All` is the platform-key
 * wildcard: the machine equivalent of a super admin.
 */
#[TypeScript]
enum ApiKeyScope: string
{
    case All = '*';
    case RestaurantsRead = 'restaurants:read';
    case RestaurantsWrite = 'restaurants:write';
    case OrdersRead = 'orders:read';
    case OrdersWrite = 'orders:write';
    case MenuRead = 'menu:read';
    case MenuWrite = 'menu:write';
    case CustomersRead = 'customers:read';
    case ApiKeysManage = 'api-keys:manage';

    /**
     * Platform-wide reads (earnings, lifecycle, review queues). Only a
     * platform key or a super admin can hold it; `All` implies it.
     */
    case PlatformRead = 'platform:read';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Full access',
            self::RestaurantsRead => 'Read restaurant profile and hours',
            self::RestaurantsWrite => 'Change the restaurant\'s logo, hero, about and gallery images',
            self::OrdersRead => 'Read orders and the kitchen board',
            self::OrdersWrite => 'Move orders between statuses',
            self::MenuRead => 'Read the full menu, hidden items included',
            self::MenuWrite => 'Change menu item availability',
            self::CustomersRead => 'Read the customer list',
            self::ApiKeysManage => 'Create and revoke this restaurant\'s API keys',
            self::PlatformRead => 'Read platform-wide reports (earnings, lifecycle)',
        };
    }

    /**
     * The scopes a restaurant-scoped key may be granted: everything that acts
     * within one restaurant.
     *
     * @return array<int, self>
     */
    public static function restaurantScopes(): array
    {
        return array_values(array_filter(self::cases(), fn (self $scope) => ! $scope->isPlatformOnly()));
    }

    /**
     * Scopes that only make sense on a platform key.
     */
    public function isPlatformOnly(): bool
    {
        return $this === self::All || $this === self::PlatformRead;
    }

    /**
     * The scopes a signed-in operator holds at a restaurant, by pivot role.
     * Staff run the kitchen; admins also manage the menu, customers and keys.
     *
     * @return array<int, self>
     */
    public static function forRole(RestaurantRole $role): array
    {
        return match ($role) {
            RestaurantRole::Admin => self::restaurantScopes(),
            RestaurantRole::Staff => [
                self::RestaurantsRead,
                self::OrdersRead,
                self::OrdersWrite,
                self::MenuRead,
            ],
        };
    }
}

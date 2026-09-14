<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\EarningsByRestaurant;
use App\Mcp\Tools\EarningsLedger;
use App\Mcp\Tools\EarningsSummary;
use App\Mcp\Tools\GetMenu;
use App\Mcp\Tools\GetOrder;
use App\Mcp\Tools\GetRestaurant;
use App\Mcp\Tools\KitchenBoard;
use App\Mcp\Tools\ListCustomers;
use App\Mcp\Tools\ListGalleryPhotos;
use App\Mcp\Tools\ListOrders;
use App\Mcp\Tools\ListRestaurants;
use App\Mcp\Tools\RemoveImage;
use App\Mcp\Tools\SetMenuItemAvailability;
use App\Mcp\Tools\TransitionOrder;
use App\Mcp\Tools\UploadImage;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

/**
 * The operator API as MCP tools, for Claude and any other agent holding an
 * API key. A platform key reaches every restaurant; a restaurant key only
 * its own, within its scopes.
 */
#[Name('Plateful')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
Plateful is a multi-tenant restaurant ordering platform. Every tool except
`list-restaurants` takes a `restaurant` argument: the restaurant's subdomain,
as returned by `list-restaurants`. Money is in integer cents. Timestamps are
ISO 8601 in UTC. Order statuses flow pending → confirmed → preparing → ready →
completed, with cancelled reachable from the earlier states; `transition-order`
refuses illegal moves. Writes (`transition-order`, `set-menu-item-availability`)
act on live restaurants immediately: confirm with the person before using
them unless they asked for exactly that change.
MARKDOWN)]
class PlatformServer extends Server
{
    /**
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListRestaurants::class,
        GetRestaurant::class,
        ListOrders::class,
        GetOrder::class,
        KitchenBoard::class,
        TransitionOrder::class,
        ListCustomers::class,
        GetMenu::class,
        SetMenuItemAvailability::class,
        ListGalleryPhotos::class,
        UploadImage::class,
        RemoveImage::class,
        // Platform-only (platform:read).
        EarningsSummary::class,
        EarningsByRestaurant::class,
        EarningsLedger::class,
    ];
}

<?php

use App\Enums\ApiKeyScope;
use App\Enums\OrderStatus;
use App\Mcp\Servers\PlatformServer;
use App\Mcp\Tools\GetMenu;
use App\Mcp\Tools\GetOrder;
use App\Mcp\Tools\GetRestaurant;
use App\Mcp\Tools\KitchenBoard;
use App\Mcp\Tools\ListCustomers;
use App\Mcp\Tools\ListOrders;
use App\Mcp\Tools\ListRestaurants;
use App\Mcp\Tools\SetMenuItemAvailability;
use App\Mcp\Tools\TransitionOrder;
use App\Models\ApiKey;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Mail;
use Laravel\Mcp\Server\Transport\StdioTransport;

require_once __DIR__.'/../Api/V1/ApiHelpers.php';
require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';
require_once __DIR__.'/../Admin/CustomerTestHelpers.php';
require_once __DIR__.'/../Storefront/CartTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    Mail::fake();
});

/**
 * @return array{platform: ApiKey, restaurantKey: ApiKey, restaurant: Restaurant}
 */
function mcpFixture(): array
{
    $restaurant = adminOrderRestaurant('marcos');

    return [
        'platform' => ApiKey::factory()->platform()->create(['name' => 'Claude']),
        'restaurantKey' => ApiKey::factory()->for($restaurant)->create(),
        'restaurant' => $restaurant,
    ];
}

test('the server lists its tools with annotations', function () {
    $tools = collect((new PlatformServer(new StdioTransport('t')))->createContext()->tools())
        ->keyBy(fn ($tool) => $tool->name());

    expect($tools->keys()->all())->toBe([
        'list-restaurants', 'get-restaurant', 'list-orders', 'get-order', 'kitchen-board',
        'transition-order', 'list-customers', 'get-menu', 'set-menu-item-availability',
        'earnings-summary', 'earnings-by-restaurant', 'earnings-ledger',
    ])
        ->and($tools['list-orders']->toArray()['annotations'])->toHaveKey('readOnlyHint', true)
        ->and($tools['transition-order']->toArray()['annotations'])->not->toHaveKey('readOnlyHint')
        ->and($tools['list-orders']->toArray()['inputSchema']['required'])->toBe(['restaurant']);
});

test('list-restaurants shows a platform key everything and a restaurant key its own', function () {
    ['platform' => $platform, 'restaurantKey' => $key] = mcpFixture();
    Restaurant::factory()->suspended()->create(['subdomain' => 'closed']);

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(ListRestaurants::class)
        ->assertOk()
        ->assertSee('"isPlatform":true')
        ->assertSee('marcos')
        ->assertSee('closed');

    PlatformServer::actingAs($key, 'api-key')
        ->tool(ListRestaurants::class)
        ->assertOk()
        ->assertSee('marcos')
        ->assertDontSee('closed');
});

test('a restaurant key cannot reach another restaurant or exceed its scopes', function () {
    ['restaurant' => $restaurant] = mcpFixture();
    Restaurant::factory()->create(['subdomain' => 'luigis']);
    $readOnly = ApiKey::factory()->for($restaurant)->scopes([ApiKeyScope::OrdersRead])->create();

    PlatformServer::actingAs($readOnly, 'api-key')
        ->tool(ListOrders::class, ['restaurant' => 'luigis'])
        ->assertHasErrors(['No restaurant [luigis] is available to this credential. Call list-restaurants for the ones that are.']);

    PlatformServer::actingAs($readOnly, 'api-key')
        ->tool(ListCustomers::class, ['restaurant' => 'marcos'])
        ->assertHasErrors(['This credential lacks the customers:read scope at R-marcos.']);

    PlatformServer::actingAs($readOnly, 'api-key')
        ->tool(ListOrders::class, ['restaurant' => 'marcos'])
        ->assertOk();
});

test('orders: list, get, kitchen board and transition', function () {
    ['platform' => $platform, 'restaurant' => $restaurant] = mcpFixture();
    $order = makeOrder($restaurant, ['number' => 'ABC-12345', 'customer_name' => 'Zed Zebra']);
    makeOrder($restaurant, ['number' => 'DONE-0001', 'status' => OrderStatus::Completed]);

    $server = fn () => PlatformServer::actingAs($platform, 'api-key');

    $server()->tool(ListOrders::class, ['restaurant' => 'marcos', 'status' => ['pending']])
        ->assertOk()
        ->assertSee('ABC-12345')
        ->assertDontSee('DONE-0001')
        ->assertSee('"statusCounts"');

    $server()->tool(ListOrders::class, ['restaurant' => 'marcos', 'search' => 'zebra', 'per_page' => 500])
        ->assertHasErrors();

    $server()->tool(GetOrder::class, ['restaurant' => 'marcos', 'order' => 'ABC-12345'])
        ->assertOk()
        ->assertSee('Sample item')
        ->assertSee('"paymentState"');

    $server()->tool(GetOrder::class, ['restaurant' => 'marcos', 'order' => (string) $order->id])
        ->assertOk()
        ->assertSee('ABC-12345');

    $server()->tool(GetOrder::class, ['restaurant' => 'marcos', 'order' => 'NOPE-1'])
        ->assertHasErrors(['No order [NOPE-1] at R-marcos.']);

    $server()->tool(KitchenBoard::class, ['restaurant' => 'marcos'])
        ->assertOk()
        ->assertSee('ABC-12345')
        ->assertDontSee('DONE-0001');

    $server()->tool(TransitionOrder::class, ['restaurant' => 'marcos', 'order' => 'ABC-12345', 'to_status' => 'confirmed', 'note' => 'via mcp'])
        ->assertOk()
        ->assertSee('"status":"confirmed"')
        ->assertSee('via mcp');

    expect($order->fresh()->status)->toBe(OrderStatus::Confirmed);

    $server()->tool(TransitionOrder::class, ['restaurant' => 'marcos', 'order' => 'DONE-0001', 'to_status' => 'confirmed'])
        ->assertHasErrors(['Cannot transition order from completed to confirmed.']);

    $server()->tool(TransitionOrder::class, ['restaurant' => 'marcos', 'order' => 'ABC-12345', 'to_status' => 'pending'])
        ->assertHasErrors();
});

test('menu: read with hidden items and 86 an item', function () {
    ['platform' => $platform] = mcpFixture();
    ['simple' => $soda] = cartFixture('luigis');
    $soda->update(['is_available' => false]);

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(GetMenu::class, ['restaurant' => 'luigis'])
        ->assertOk()
        ->assertSee('Soda');

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(GetMenu::class, ['restaurant' => 'luigis', 'include_hidden' => false])
        ->assertOk()
        ->assertDontSee('Soda');

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(SetMenuItemAvailability::class, ['restaurant' => 'luigis', 'menu_item_id' => $soda->id, 'is_available' => true])
        ->assertOk()
        ->assertSee('"isAvailable":true');

    expect($soda->fresh()->is_available)->toBeTrue();

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(SetMenuItemAvailability::class, ['restaurant' => 'marcos', 'menu_item_id' => $soda->id, 'is_available' => false])
        ->assertHasErrors(["No menu item [{$soda->id}] at R-marcos."]);
});

test('customers and restaurant profile', function () {
    ['platform' => $platform, 'restaurant' => $restaurant] = mcpFixture();
    customerPivot($restaurant, customerUser('Ada Diner', 'ada@example.test'));

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(ListCustomers::class, ['restaurant' => 'marcos', 'search' => 'ada'])
        ->assertOk()
        ->assertSee('ada@example.test');

    PlatformServer::actingAs($platform, 'api-key')
        ->tool(GetRestaurant::class, ['restaurant' => 'marcos'])
        ->assertOk()
        ->assertSee('"subdomain":"marcos"');
});

test('the HTTP endpoint requires a key and answers initialize', function () {
    ['platform' => $platform] = mcpFixture();
    $plain = apiKeyFor(null);

    $initialize = [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
    ];
    $headers = ['Accept' => 'application/json, text/event-stream'];

    $this->postJson('http://plateful.test/mcp/platform', $initialize, $headers)
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');

    $this->withToken($plain)->postJson('http://plateful.test/mcp/platform', $initialize, $headers)
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Plateful')
        ->assertJsonPath('result.capabilities.tools.listChanged', false);

    $this->withToken($plain)->postJson('http://plateful.test/mcp/platform', [
        'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => [],
    ], $headers)
        ->assertOk()
        ->assertJsonPath('result.tools.0.name', 'list-restaurants');

    expect($platform->fresh())->not->toBeNull();
});

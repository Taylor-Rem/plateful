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
use App\Models\ApiCallLog;
use App\Support\Api\ApiCallLogger;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

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

    /**
     * Every tools/call is written to the audit log (ApiCallLog) with the
     * key, the restaurant it touched, redacted arguments and the outcome,
     * refused and failed calls included. The log write can never change
     * the response.
     *
     * @return iterable<JsonRpcResponse>|JsonRpcResponse
     */
    protected function runMethodHandle(JsonRpcRequest $request, ServerContext $context): iterable|JsonRpcResponse
    {
        if ($request->method !== 'tools/call') {
            return parent::runMethodHandle($request, $context);
        }

        $started = hrtime(true);

        try {
            $response = parent::runMethodHandle($request, $context);
        } catch (Throwable $e) {
            $this->audit($request, $started, ok: false, error: $e->getMessage());

            throw $e;
        }

        if ($response instanceof JsonRpcResponse) {
            [$ok, $error] = $this->outcomeOf($response);
            $this->audit($request, $started, $ok, $error);
        } else {
            $this->audit($request, $started, ok: true, error: null);
        }

        return $response;
    }

    protected function audit(JsonRpcRequest $request, int $startedAt, bool $ok, ?string $error): void
    {
        $principal = Auth::guard('api-key')->user() ?? Auth::user();
        $arguments = $request->params['arguments'] ?? null;
        $arguments = is_array($arguments) ? $arguments : null;
        $named = $arguments['restaurant'] ?? null;
        $tenant = app(CurrentTenant::class)->get();

        app(ApiCallLogger::class)->record(
            channel: ApiCallLog::CHANNEL_MCP,
            action: (string) ($request->params['name'] ?? 'tools/call'),
            principal: $principal,
            // The tenant singleton may still hold an earlier call's restaurant
            // (tests, Octane), so it only counts when it is the one named.
            restaurant: is_string($named) && $named !== ''
                ? ($tenant?->subdomain === $named ? $tenant : ApiCallLogger::restaurantFor($principal, $named))
                : null,
            arguments: $arguments,
            ok: $ok,
            error: $error,
            durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    protected function outcomeOf(JsonRpcResponse $response): array
    {
        $payload = $response->toArray();

        if (isset($payload['error'])) {
            return [false, (string) ($payload['error']['message'] ?? 'error')];
        }

        $result = $payload['result'] ?? [];

        if (! (bool) ($result['isError'] ?? false)) {
            return [true, null];
        }

        $texts = array_values(array_filter(array_map(
            fn (mixed $content): ?string => is_array($content) && isset($content['text']) ? (string) $content['text'] : null,
            (array) ($result['content'] ?? []),
        )));

        return [false, $texts[0] ?? 'error'];
    }
}

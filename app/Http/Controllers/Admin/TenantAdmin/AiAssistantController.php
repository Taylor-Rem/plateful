<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\ApiCallLogData;
use App\Data\ApiKeyData;
use App\Data\RestaurantData;
use App\Enums\ApiKeyScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AiAssistantKeyRequest;
use App\Models\ApiCallLog;
use App\Models\ApiKey;
use App\Models\Restaurant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "AI assistant": where a restaurant admin connects the assistant they
 * already use (Claude, ChatGPT, Claude Code) to this restaurant. Mints a
 * restaurant-scoped API key with the chosen scopes and a per-key rate
 * limit, shows it once with the setup for each client, lists and revokes
 * keys, and shows the recent tool calls from the audit log.
 */
class AiAssistantController extends Controller
{
    /**
     * Requests per minute offered by default: far more than a person drives
     * an assistant to, far less than the platform ceiling.
     */
    public const DEFAULT_ASSISTANT_RATE_LIMIT = 60;

    public const RECENT_CALLS = 50;

    /**
     * Pre-ticked on the form: read and edit the menu and photos, read
     * orders. Moving orders, the customer list and key management stay
     * opt-in.
     *
     * @var array<int, ApiKeyScope>
     */
    private const RECOMMENDED_SCOPES = [
        ApiKeyScope::RestaurantsRead,
        ApiKeyScope::RestaurantsWrite,
        ApiKeyScope::MenuRead,
        ApiKeyScope::MenuWrite,
        ApiKeyScope::OrdersRead,
    ];

    public function show(Request $request, Restaurant $restaurant): Response
    {
        $keys = $restaurant->apiKeys()
            ->with('createdBy')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ApiKey $key) => ApiKeyData::fromModel($key))
            ->all();

        $calls = ApiCallLog::query()
            ->where('restaurant_id', $restaurant->id)
            ->with(['apiKey', 'user'])
            ->orderByDesc('id')
            ->limit(self::RECENT_CALLS)
            ->get()
            ->map(fn (ApiCallLog $log) => ApiCallLogData::fromModel($log))
            ->all();

        return Inertia::render('Admin/TenantAdmin/AiAssistant', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'keys' => $keys,
            'calls' => $calls,
            'scopes' => collect(ApiKeyScope::restaurantScopes())
                ->map(fn (ApiKeyScope $scope) => [
                    'value' => $scope->value,
                    'label' => $scope->label(),
                    'recommended' => in_array($scope, self::RECOMMENDED_SCOPES, true),
                ])
                ->all(),
            'mcpUrl' => route('mcp.platform'),
            // A path on the current (admin) host: the page posts here and
            // revokes at `{keysPath}/{id}`, so it never depends on the host
            // Wayfinder baked in at build time and works under the browser
            // test server, which only answers on 127.0.0.1.
            'keysPath' => route('admin.restaurant.ai.keys.store', ['restaurant' => $restaurant->subdomain], absolute: false),
            'defaultRateLimit' => self::DEFAULT_ASSISTANT_RATE_LIMIT,
            'maxRateLimit' => ApiKey::DEFAULT_RATE_LIMIT_PER_MINUTE,
            // Set by store() for exactly one page load: the plaintext key
            // and the URLs built from it are never stored.
            'createdKey' => $request->session()->get('createdKey'),
        ]);
    }

    public function store(AiAssistantKeyRequest $request, Restaurant $restaurant): RedirectResponse
    {
        ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint(
            name: (string) $request->validated('name'),
            scopes: $request->scopes(),
            restaurant: $restaurant,
            createdBy: $request->user(),
            rateLimitPerMinute: (int) $request->validated('rate_limit_per_minute'),
        );

        $key->setRelation('createdBy', $request->user());

        return back()
            ->with('success', "Key \"{$key->name}\" created. Copy it now; it will not be shown again.")
            ->with('createdKey', [
                'key' => ApiKeyData::fromModel($key)->toArray(),
                'plainTextKey' => $plain,
                'mcpUrl' => route('mcp.platform'),
                'connectUrl' => route('mcp.platform.connect', ['connectKey' => $plain]),
            ]);
    }

    public function destroy(Restaurant $restaurant, int $apiKey): RedirectResponse
    {
        $key = $restaurant->apiKeys()->whereKey($apiKey)->first();

        if ($key === null) {
            abort(404);
        }

        $key->revoke();

        return back()
            ->with('success', "Key \"{$key->name}\" revoked. Anything still using it is refused from now on.");
    }
}

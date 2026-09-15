<?php

namespace App\Http\Middleware;

use App\Models\ApiCallLog;
use App\Models\Restaurant;
use App\Support\Api\ApiCallLogger;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * `operator.log` — the REST half of the audit trail behind the AI assistant
 * page: one ApiCallLog row per operator request, named by route, with the
 * restaurant it addressed and the outcome. Sits after `auth` and `throttle`
 * in the group (so strangers and rate-limited bursts never reach it) and
 * before `operator.restaurant` / `operator.scope`, whose 404s and 403s it
 * records as refused calls. Those arrive as exceptions (the routing pipeline
 * renders them after the middleware stack unwinds), so both paths are logged.
 */
class LogOperatorApiCall
{
    private const ROUTE_PREFIX = 'api.v1.operator.';

    public function __construct(
        protected ApiCallLogger $logger,
        protected CurrentTenant $currentTenant,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $this->log($request, $started, $status, $e->getMessage() !== '' ? $e->getMessage() : "HTTP {$status}");

            throw $e;
        }

        $status = $response->getStatusCode();
        $this->log($request, $started, $status, $status >= 400 ? $this->errorMessage($response, $status) : null);

        return $response;
    }

    protected function log(Request $request, int $startedAt, int $status, ?string $error): void
    {
        $this->logger->record(
            channel: ApiCallLog::CHANNEL_REST,
            action: $this->action($request),
            principal: $request->user(),
            restaurant: $this->routeRestaurant($request),
            arguments: $request->except(['image', 'password']),
            ok: $status < 400,
            error: $error,
            durationMs: (int) round((hrtime(true) - $startedAt) / 1_000_000),
        );
    }

    protected function action(Request $request): string
    {
        $name = $request->route()?->getName();

        if (is_string($name) && $name !== '') {
            return str_starts_with($name, self::ROUTE_PREFIX) ? substr($name, strlen(self::ROUTE_PREFIX)) : $name;
        }

        return $request->method().' '.$request->path();
    }

    /**
     * The restaurant the route addressed, if any: the resolved tenant when
     * it is the one named in the path (the singleton can hold a previous
     * request's tenant under Octane or in tests), else looked up the way
     * the tenant middleware would, else the key's own restaurant.
     */
    protected function routeRestaurant(Request $request): ?Restaurant
    {
        $route = $request->route();

        if ($route === null || ! $route->hasParameter('restaurant')) {
            return null;
        }

        $parameter = $route->parameter('restaurant');

        if ($parameter instanceof Restaurant) {
            return $parameter;
        }

        $tenant = $this->currentTenant->get();

        if ($tenant !== null && $tenant->subdomain === $parameter) {
            return $tenant;
        }

        return ApiCallLogger::restaurantFor($request->user(), $parameter);
    }

    protected function errorMessage(Response $response, int $status): string
    {
        $content = $response->getContent();
        $decoded = is_string($content) ? json_decode($content, true) : null;

        if (is_array($decoded) && isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return "HTTP {$status}";
    }
}

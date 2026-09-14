<?php

namespace App\Mcp\Tools;

use App\Enums\ApiKeyScope;
use App\Models\Restaurant;
use App\Support\Api\ApiActor;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Spatie\LaravelData\Data;

/**
 * Shared footing for the platform tools: the same ApiActor checks as the
 * operator REST routes (`operator.restaurant` + `operator.scope`), so a key
 * can do nothing here it could not do over HTTP. Failed checks throw
 * AuthorizationException, which CallTool turns into a tool error.
 */
abstract class OperatorTool extends Tool
{
    protected function actor(Request $request): ApiActor
    {
        return ApiActor::for($request->user());
    }

    /**
     * Resolve the `restaurant` argument (a subdomain) for the actor, require
     * the scope there, and set the tenant so scoped models resolve inside it.
     *
     * @throws AuthorizationException
     */
    protected function restaurant(Request $request, ApiKeyScope $scope): Restaurant
    {
        $actor = $this->actor($request);
        $subdomain = trim((string) $request->get('restaurant'));

        $restaurant = $subdomain !== ''
            ? Restaurant::query()->where('subdomain', $subdomain)->first()
            : null;

        if ($restaurant === null || ! $actor->canAccessRestaurant($restaurant)) {
            throw new AuthorizationException("No restaurant [{$subdomain}] is available to this credential. Call list-restaurants for the ones that are.");
        }

        if (! $actor->hasScope($scope, $restaurant)) {
            throw new AuthorizationException("This credential lacks the {$scope->value} scope at {$restaurant->name}.");
        }

        app(CurrentTenant::class)->set($restaurant);

        return $restaurant;
    }

    protected function restaurantArgument(JsonSchema $schema): mixed
    {
        return $schema->string()
            ->description('The restaurant\'s subdomain, as returned by list-restaurants.')
            ->required();
    }

    /**
     * @param  Data|array<mixed>  $data
     */
    protected function json(Data|array $data): Response
    {
        return Response::json($data instanceof Data ? $data->toArray() : $data);
    }
}

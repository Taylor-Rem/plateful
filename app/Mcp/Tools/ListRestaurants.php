<?php

namespace App\Mcp\Tools;

use App\Data\OperatorRestaurantData;
use App\Models\Restaurant;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the restaurants this credential can operate, with lifecycle status, live flag, and the scopes held at each. Start here: every other tool takes a restaurant subdomain from this list.')]
class ListRestaurants extends OperatorTool
{
    public function handle(Request $request): Response
    {
        $actor = $this->actor($request);

        return $this->json([
            'actor' => ['type' => $actor->type(), 'name' => $actor->name(), 'isPlatform' => $actor->isPlatform()],
            'data' => $actor->restaurants()
                ->map(fn (Restaurant $r) => OperatorRestaurantData::fromModel($r, $actor)->toArray())
                ->values()
                ->all(),
        ]);
    }
}

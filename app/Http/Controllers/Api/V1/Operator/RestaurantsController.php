<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Data\OperatorRestaurantData;
use App\Data\RestaurantData;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Support\Api\ApiActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $actor = ApiActor::fromRequest($request);

        return response()->json([
            'data' => $actor->restaurants()
                ->map(fn (Restaurant $r) => OperatorRestaurantData::fromModel($r, $actor))
                ->values()
                ->all(),
        ]);
    }

    public function show(Restaurant $restaurant): JsonResponse
    {
        $restaurant->load(['hours', 'photos']);

        return response()->json(['data' => RestaurantData::fromModel($restaurant)]);
    }
}

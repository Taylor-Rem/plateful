<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Data\RestaurantSummaryData;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoritesController extends Controller
{
    /**
     * Favourited restaurants that are still live; a closed one drops out
     * of the list but keeps its row, so it returns if the restaurant does.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $request->user()->favoriteRestaurants()
                ->public()
                ->with('hours')
                ->orderBy('name')
                ->get()
                ->map(fn (Restaurant $r) => RestaurantSummaryData::fromModel($r))
                ->all(),
        ]);
    }
}

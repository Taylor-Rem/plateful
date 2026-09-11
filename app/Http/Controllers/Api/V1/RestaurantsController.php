<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\MenuCategoryData;
use App\Data\PaginationMetaData;
use App\Data\RestaurantData;
use App\Data\RestaurantSummaryData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RestaurantSearchRequest;
use App\Models\Restaurant;
use App\Support\Menus\StorefrontMenuQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Discovery and read endpoints for the app. `index` is the marketplace
 * (listed restaurants only); `show` and `menu` resolve any live restaurant
 * by subdomain through the `tenant.route` middleware, so an opted-out
 * restaurant still opens from a link or a past order.
 */
class RestaurantsController extends Controller
{
    public function index(RestaurantSearchRequest $request): JsonResponse
    {
        $results = $request->toSearch()->get();
        $perPage = (int) config('platform.marketplace.per_page', 20);
        $page = Paginator::resolveCurrentPage();

        $paginator = new LengthAwarePaginator(
            $results->forPage($page, $perPage)->values(),
            $results->count(),
            $perPage,
            $page,
        );

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(fn (Restaurant $r) => RestaurantSummaryData::fromModel($r))
                ->all(),
            'meta' => PaginationMetaData::fromPaginator($paginator),
        ]);
    }

    public function show(Restaurant $restaurant): JsonResponse
    {
        $restaurant->load(['hours', 'photos']);

        return response()->json(['data' => RestaurantData::fromModel($restaurant)]);
    }

    /**
     * @return JsonResponse{data: array<int, MenuCategoryData>}
     */
    public function menu(Restaurant $restaurant, StorefrontMenuQuery $menu): JsonResponse
    {
        return response()->json(['data' => $menu->categoryData($restaurant)]);
    }
}

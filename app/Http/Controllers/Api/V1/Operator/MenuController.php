<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Data\MenuItemData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operator\MenuItemAvailabilityRequest;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Support\Menus\StorefrontMenuQuery;
use Illuminate\Http\JsonResponse;

/**
 * The operator's menu: the storefront tree with hidden categories and
 * unavailable items included, plus the one write a kitchen needs mid-shift.
 */
class MenuController extends Controller
{
    public function index(Restaurant $restaurant, StorefrontMenuQuery $menu): JsonResponse
    {
        return response()->json(['data' => $menu->categoryData($restaurant, includeHidden: true)]);
    }

    public function availability(MenuItemAvailabilityRequest $request, Restaurant $restaurant, MenuItem $menuItem): JsonResponse
    {
        $menuItem->update(['is_available' => (bool) $request->validated('is_available')]);

        return response()->json(['data' => MenuItemData::fromModel($menuItem->refresh())]);
    }
}

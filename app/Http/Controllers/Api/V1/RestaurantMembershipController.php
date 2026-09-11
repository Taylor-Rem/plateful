<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\RestaurantMembershipData;
use App\Enums\MarketingConsentSource;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Services\MarketingConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in customer's relationship with one restaurant, and the two
 * things they can change about it from the app: favourite, and marketing
 * email consent (per restaurant, strict opt-in, same audit trail as the web).
 */
class RestaurantMembershipController extends Controller
{
    public function show(Request $request, Restaurant $restaurant): JsonResponse
    {
        return $this->membership($request, $restaurant);
    }

    public function favorite(Request $request, Restaurant $restaurant): JsonResponse
    {
        $request->user()->favoriteRestaurants()->syncWithoutDetaching([$restaurant->id]);

        return $this->membership($request, $restaurant);
    }

    public function unfavorite(Request $request, Restaurant $restaurant): JsonResponse
    {
        $request->user()->favoriteRestaurants()->detach($restaurant->id);

        return $this->membership($request, $restaurant);
    }

    public function marketingConsent(Request $request, Restaurant $restaurant, MarketingConsentService $consent): JsonResponse
    {
        $validated = $request->validate(['opted_in' => ['required', 'boolean']]);

        if ($validated['opted_in']) {
            $consent->optInEmail($request->user(), $restaurant, MarketingConsentSource::Account, $request->ip(), $request->userAgent());
        } else {
            $consent->optOutEmail($request->user(), $restaurant, MarketingConsentSource::Account, $request->ip(), $request->userAgent());
        }

        return $this->membership($request, $restaurant);
    }

    protected function membership(Request $request, Restaurant $restaurant): JsonResponse
    {
        return response()->json(['data' => RestaurantMembershipData::for($request->user(), $restaurant)]);
    }
}

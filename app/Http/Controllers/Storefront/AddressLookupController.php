<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Services\Places\GooglePlacesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Proxies Google Places for the checkout address field so the API key stays
 * server-side. Public — a customer checks out without an account — which is why
 * both actions are rate limited: every call costs money at Google.
 */
class AddressLookupController extends Controller
{
    public function suggest(Request $request, GooglePlacesService $places): JsonResponse
    {
        $validated = $request->validate([
            'input' => ['required', 'string', 'max:200'],
            'session_token' => ['required', 'string', 'max:100'],
        ]);

        return response()->json([
            'suggestions' => $places->autocomplete($validated['input'], $validated['session_token']),
        ]);
    }

    public function resolve(Request $request, GooglePlacesService $places): JsonResponse
    {
        $validated = $request->validate([
            'place_id' => ['required', 'string', 'max:255'],
            'session_token' => ['required', 'string', 'max:100'],
        ]);

        $snapshot = $places->addressSnapshot($validated['place_id'], $validated['session_token']);

        if ($snapshot === null) {
            return response()->json([
                'message' => 'We couldn’t read a street address from that result. Try picking a more specific one.',
            ], 422);
        }

        return response()->json(['address' => $snapshot]);
    }
}

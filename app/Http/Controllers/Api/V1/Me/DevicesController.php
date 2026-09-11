<?php

namespace App\Http\Controllers\Api\V1\Me;

use App\Data\DeviceTokenData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Me\RegisterDeviceRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Push registration. The app registers its Expo token after sign-in and
 * whenever Expo rotates it, and removes it before signing out so a shared
 * phone never pushes one person's orders to the next.
 */
class DevicesController extends Controller
{
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $device = DeviceToken::query()->updateOrCreate(
            ['token' => $request->input('token')],
            [
                'user_id' => $request->user()->id,
                'provider' => DeviceToken::PROVIDER_EXPO,
                'platform' => $request->input('platform'),
                'device_name' => $request->input('device_name'),
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['data' => DeviceTokenData::fromModel($device)], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request): Response
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:255']]);

        $request->user()->deviceTokens()->where('token', $validated['token'])->delete();

        return response()->noContent();
    }
}

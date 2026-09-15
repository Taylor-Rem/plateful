<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Data\ApiKeyCreatedData;
use App\Data\ApiKeyData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Operator\ApiKeyStoreRequest;
use App\Models\ApiKey;
use App\Models\Restaurant;
use App\Support\Api\ApiActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A restaurant's own keys. Platform keys are minted from the console
 * (`php artisan api-key:create --platform`) and never appear here.
 */
class ApiKeysController extends Controller
{
    public function index(Restaurant $restaurant): JsonResponse
    {
        return response()->json([
            'data' => $restaurant->apiKeys()
                ->with('createdBy')
                ->orderByDesc('id')
                ->get()
                ->map(fn (ApiKey $key) => ApiKeyData::fromModel($key))
                ->all(),
        ]);
    }

    public function store(ApiKeyStoreRequest $request, Restaurant $restaurant): JsonResponse
    {
        $expiresAt = $request->validated('expires_at');

        ['key' => $key, 'plainTextKey' => $plain] = ApiKey::mint(
            name: (string) $request->validated('name'),
            scopes: $request->scopes(),
            restaurant: $restaurant,
            createdBy: ApiActor::fromRequest($request)->auditUser(),
            expiresAt: $expiresAt !== null ? now()->parse($expiresAt) : null,
            rateLimitPerMinute: $request->validated('rate_limit_per_minute') !== null
                ? (int) $request->validated('rate_limit_per_minute')
                : null,
        );

        return response()->json(['data' => ApiKeyCreatedData::fromMint($key, $plain)], 201);
    }

    public function destroy(Restaurant $restaurant, int $apiKey): Response
    {
        $key = $restaurant->apiKeys()->whereKey($apiKey)->first();

        if ($key === null) {
            throw new NotFoundHttpException;
        }

        $key->revoke();

        return response()->noContent();
    }
}

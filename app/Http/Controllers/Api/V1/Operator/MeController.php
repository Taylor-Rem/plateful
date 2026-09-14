<?php

namespace App\Http\Controllers\Api\V1\Operator;

use App\Data\OperatorActorData;
use App\Http\Controllers\Controller;
use App\Support\Api\ApiActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who signed the request and which restaurants they can operate — the first
 * call any operator client makes.
 */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => OperatorActorData::fromActor(ApiActor::fromRequest($request))]);
    }
}

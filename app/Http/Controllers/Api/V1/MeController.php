<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\MeData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => MeData::fromModel($request->user())]);
    }

    /**
     * In-app account deletion (an App Store requirement). Same semantics as
     * the web profile page: a genuine hard delete of the record and its PII,
     * not the recoverable soft-delete reserved for admin-initiated removal.
     * The bearer token is the proof of intent; the app confirms in its UI.
     */
    public function destroy(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_if($user->isLastSuperAdmin(), 409, 'The last super admin cannot delete their account.');

        $user->tokens()->delete();
        $user->forceDelete();

        return response()->noContent();
    }
}

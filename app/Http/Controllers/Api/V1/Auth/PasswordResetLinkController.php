<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    /**
     * Email a reset link; the reset itself completes on the web. Always 200
     * so the endpoint cannot be used to discover which emails have accounts.
     */
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink($request->only('email'));

        return response()->json([
            'message' => __('If an account exists for that email, a reset link is on its way.'),
        ]);
    }
}

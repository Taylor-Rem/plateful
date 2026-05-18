<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Fortify;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $adminHost = 'admin.'.config('platform.primary_domain');

        if ($request->getHost() === $adminHost) {
            return redirect()->intended('/');
        }

        return redirect()->intended(Fortify::redirects('login'));
    }
}

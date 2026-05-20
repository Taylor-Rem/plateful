<?php

namespace App\Listeners;

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\User;
use App\Services\CartManager;
use App\Tenancy\CurrentTenant;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;

class MergeGuestCartOnLogin
{
    public function __construct(
        protected Request $request,
        protected CartManager $manager,
        protected CurrentTenant $tenant,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || $user->role !== UserRole::Customer) {
            return;
        }

        if (! $this->tenant->check()) {
            return;
        }

        $token = $this->request->cookie(CartManager::COOKIE_NAME);
        if (! $token) {
            return;
        }

        $guestCart = Cart::query()->where('token', $token)->first();

        if (! $guestCart
            || $guestCart->restaurant_id !== $this->tenant->id()
            || $guestCart->user_id !== null
        ) {
            return;
        }

        $this->manager->mergeGuestCartIntoUser($guestCart, $user);
    }
}

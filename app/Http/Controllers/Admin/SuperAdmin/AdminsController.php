<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AdminsController extends Controller
{
    public function index(): Response
    {
        // An "admin" is any user who is super admin OR a member of a restaurant
        // via the restaurant_user pivot. (Customers who have only ordered are
        // not admins and are excluded.)
        $admins = User::query()
            ->where(function ($q) {
                $q->where('is_super_admin', true)
                    ->orWhereHas('restaurants');
            })
            ->with('restaurants:id,name,subdomain')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isSuperAdmin' => $user->is_super_admin,
                'restaurants' => $user->restaurants->map(fn ($r) => [
                    'id' => $r->id,
                    'name' => $r->name,
                    'subdomain' => $r->subdomain,
                ])->all(),
            ])
            ->all();

        $restaurants = Restaurant::query()
            ->orderBy('name')
            ->get(['id', 'name', 'subdomain'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'subdomain' => $r->subdomain,
            ])
            ->all();

        return Inertia::render('Admin/SuperAdmin/Admins', [
            'admins' => $admins,
            'restaurants' => $restaurants,
        ]);
    }
}

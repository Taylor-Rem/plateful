<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

class AdminsController extends Controller
{
    public function index(): Response
    {
        $admins = User::query()
            ->where('role', UserRole::Admin)
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

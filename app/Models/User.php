<?php

namespace App\Models;

use App\Enums\RestaurantRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

#[Fillable(['name', 'email', 'phone', 'password', 'is_super_admin'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_super_admin' => 'bool',
        ];
    }

    /**
     * Restaurants the user is an admin/staff member of (admin-side pivot).
     */
    public function restaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'restaurant_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Restaurants the user has a customer relationship with (ordered from
     * or signed up at). Distinct from `restaurants()` which is admin-side.
     */
    public function customerRestaurants(): BelongsToMany
    {
        return $this->belongsToMany(Restaurant::class, 'restaurant_customer')
            ->withPivot(['first_ordered_at', 'last_ordered_at', 'total_orders', 'total_spent_cents'])
            ->withTimestamps();
    }

    public function restaurantCustomers(): HasMany
    {
        return $this->hasMany(RestaurantCustomer::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_super_admin;
    }

    /**
     * True if this user has any platform-admin capability — either super admin
     * or a member of at least one restaurant's staff/admin pivot.
     */
    public function isAdmin(): bool
    {
        return $this->isSuperAdmin() || $this->restaurants()->exists();
    }

    public function canAccessRestaurant(Restaurant|int $restaurant): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $restaurantId = $restaurant instanceof Restaurant ? $restaurant->id : $restaurant;

        return $this->restaurants()->where('restaurants.id', $restaurantId)->exists();
    }

    public function roleAt(Restaurant|int $restaurant): ?RestaurantRole
    {
        if ($this->isSuperAdmin()) {
            return RestaurantRole::Admin;
        }

        $restaurantId = $restaurant instanceof Restaurant ? $restaurant->id : $restaurant;

        $pivot = $this->restaurants()
            ->where('restaurants.id', $restaurantId)
            ->first()?->pivot;

        if (! $pivot) {
            return null;
        }

        return RestaurantRole::tryFrom((string) $pivot->role);
    }

    public function isRestaurantAdminAt(Restaurant|int $restaurant): bool
    {
        return $this->roleAt($restaurant) === RestaurantRole::Admin;
    }

    public function isRestaurantStaffAt(Restaurant|int $restaurant): bool
    {
        return $this->roleAt($restaurant) === RestaurantRole::Staff;
    }

    /**
     * @return Collection<int, Restaurant>
     */
    public function accessibleRestaurants(): Collection
    {
        if ($this->isSuperAdmin()) {
            return Restaurant::query()->orderBy('name')->get();
        }

        return $this->restaurants()->orderBy('name')->get();
    }
}

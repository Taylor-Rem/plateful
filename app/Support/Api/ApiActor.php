<?php

namespace App\Support\Api;

use App\Enums\ApiKeyScope;
use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Models\ApiKey;
use App\Models\Restaurant;
use App\Models\User;
use App\Services\Auth\ApiTokenIssuer;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Whoever signed an operator request: a person holding a Sanctum token with
 * the `operator` ability, or a machine holding an API key. Both the REST
 * controllers and the MCP tools ask this object the same two questions —
 * can you reach this restaurant, and may you do this to it — so the answer
 * never depends on which door the caller came through.
 */
final class ApiActor
{
    private function __construct(
        public readonly ?User $user,
        public readonly ?ApiKey $key,
    ) {}

    /**
     * Wrap the request's authenticated principal. A signed-in user must hold
     * a token that carries the operator ability; a customer-only token is
     * refused here rather than silently downgraded.
     *
     * @throws AuthenticationException when nobody signed the request
     * @throws AccessDeniedHttpException when the token cannot operate
     */
    public static function for(?Authenticatable $principal): self
    {
        if ($principal instanceof ApiKey) {
            return new self(null, $principal);
        }

        if ($principal instanceof User) {
            $token = $principal->currentAccessToken();

            if ($token === null || ! $token->can(ApiTokenIssuer::ABILITY_OPERATOR)) {
                throw new AccessDeniedHttpException('This token cannot operate restaurants.');
            }

            return new self($principal, null);
        }

        throw new AuthenticationException;
    }

    public static function fromRequest(Request $request): self
    {
        return self::for($request->user());
    }

    public function isUser(): bool
    {
        return $this->user !== null;
    }

    public function isKey(): bool
    {
        return $this->key !== null;
    }

    /**
     * Platform actors (super admins, platform keys) reach every restaurant,
     * suspended ones included.
     */
    public function isPlatform(): bool
    {
        return $this->user?->isSuperAdmin() || $this->key?->isPlatform() || false;
    }

    public function canAccessRestaurant(Restaurant $restaurant): bool
    {
        if ($this->isPlatform()) {
            return true;
        }

        // A suspended restaurant is off for its own staff and keys; the
        // platform can still see it to lift the suspension.
        if ($restaurant->status === RestaurantStatus::Suspended) {
            return false;
        }

        if ($this->key !== null) {
            return $this->key->restaurant_id === $restaurant->id;
        }

        return $this->user->canAccessRestaurant($restaurant);
    }

    /**
     * Whether the actor may perform `$scope`, at `$restaurant` when given.
     * Keys carry their scopes; users derive them from their pivot role.
     */
    public function hasScope(ApiKeyScope $scope, ?Restaurant $restaurant = null): bool
    {
        if ($this->key !== null) {
            return $this->key->hasScope($scope);
        }

        if ($this->user->isSuperAdmin()) {
            return true;
        }

        if ($restaurant === null) {
            return false;
        }

        $role = $this->user->roleAt($restaurant);

        return $role !== null && in_array($scope, ApiKeyScope::forRole($role), true);
    }

    /**
     * The scopes the actor holds at a restaurant, for the `me` endpoint and
     * the restaurant list.
     *
     * @return array<int, ApiKeyScope>
     */
    public function scopesAt(?Restaurant $restaurant = null): array
    {
        if ($this->key !== null) {
            return $this->key->scopeCases();
        }

        if ($this->user->isSuperAdmin()) {
            return [ApiKeyScope::All];
        }

        $role = $restaurant !== null ? $this->user->roleAt($restaurant) : null;

        return $role !== null ? ApiKeyScope::forRole($role) : [];
    }

    public function roleAt(Restaurant $restaurant): ?RestaurantRole
    {
        if ($this->key !== null) {
            return $this->key->isPlatform() ? RestaurantRole::Admin : null;
        }

        return $this->user->roleAt($restaurant);
    }

    /**
     * Every restaurant the actor may operate, by name.
     *
     * @return Collection<int, Restaurant>
     */
    public function restaurants(): Collection
    {
        if ($this->isPlatform()) {
            return Restaurant::query()->orderBy('name')->get();
        }

        if ($this->key !== null) {
            return Restaurant::query()
                ->whereKey($this->key->restaurant_id)
                ->where('status', '!=', RestaurantStatus::Suspended)
                ->get();
        }

        return $this->user->accessibleRestaurants()
            ->reject(fn (Restaurant $r) => $r->status === RestaurantStatus::Suspended)
            ->values();
    }

    /**
     * The person to record on audit rows (order events); null for keys,
     * which are not people.
     */
    public function auditUser(): ?User
    {
        return $this->user;
    }

    public function type(): string
    {
        return $this->key !== null ? 'api_key' : 'user';
    }

    public function name(): string
    {
        return $this->key?->name ?? (string) $this->user?->name;
    }

    public function label(): string
    {
        if ($this->key !== null) {
            return $this->key->name.' ('.($this->key->isPlatform() ? 'platform key' : 'restaurant key').')';
        }

        return $this->user->name.' (user)';
    }
}

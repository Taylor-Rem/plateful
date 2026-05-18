<?php

namespace App\Providers;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Observers\MenuItemObserver;
use App\Observers\RestaurantObserver;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Restaurant::observe(RestaurantObserver::class);
        MenuItem::observe(MenuItemObserver::class);

        Route::bind('restaurant', function ($value) {
            $restaurant = Restaurant::query()->where('subdomain', $value)->first();

            if (! $restaurant) {
                throw new NotFoundHttpException;
            }

            return $restaurant;
        });

        Route::bind('category', function ($value) {
            $restaurant = request()->route('restaurant');
            $restaurantId = $restaurant instanceof Restaurant ? $restaurant->id : null;

            $category = MenuCategory::withoutTenantScope()
                ->when($restaurantId, fn ($q) => $q->where('restaurant_id', $restaurantId))
                ->where('id', $value)
                ->first();

            if (! $category || ($restaurantId && $category->restaurant_id !== $restaurantId)) {
                throw new NotFoundHttpException;
            }

            return $category;
        });

        Route::bind('item', function ($value) {
            $restaurant = request()->route('restaurant');
            $restaurantId = $restaurant instanceof Restaurant ? $restaurant->id : null;

            $item = MenuItem::withoutTenantScope()
                ->when($restaurantId, fn ($q) => $q->where('restaurant_id', $restaurantId))
                ->where('id', $value)
                ->first();

            if (! $item || ($restaurantId && $item->restaurant_id !== $restaurantId)) {
                throw new NotFoundHttpException;
            }

            return $item;
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

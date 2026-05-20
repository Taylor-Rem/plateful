<?php

namespace App\Providers;

use App\Listeners\MergeGuestCartOnLogin;
use App\Models\ItemTemplate;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Observers\MenuItemObserver;
use App\Observers\RestaurantObserver;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
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

        Event::listen(Login::class, MergeGuestCartOnLogin::class);

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

        Route::bind('template', function ($value) {
            $restaurant = request()->route('restaurant');
            $restaurantId = $restaurant instanceof Restaurant ? $restaurant->id : null;

            $template = ItemTemplate::withoutTenantScope()
                ->when($restaurantId, fn ($q) => $q->where('restaurant_id', $restaurantId))
                ->where('id', $value)
                ->first();

            if (! $template || ($restaurantId && $template->restaurant_id !== $restaurantId)) {
                throw new NotFoundHttpException;
            }

            return $template;
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

        if (app()->isProduction()) {
            URL::forceScheme('https');
        }

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

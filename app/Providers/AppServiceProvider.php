<?php

namespace App\Providers;

use App\Enums\DeliveryProviderName;
use App\Listeners\MergeGuestCartOnLogin;
use App\Listeners\PurgeUserSessionsOnLogout;
use App\Models\ItemTemplate;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Observers\MenuItemObserver;
use App\Observers\RestaurantObserver;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\SelfDeliveryProvider;
use App\Services\Pos\PosDispatcher;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);

        $this->app->singleton(StripeClient::class, function (): StripeClient {
            return new StripeClient((string) config('services.stripe.secret'));
        });

        $this->app->singleton(DeliveryDispatcher::class, function ($app): DeliveryDispatcher {
            return new DeliveryDispatcher([
                DeliveryProviderName::Self->value => $app->make(SelfDeliveryProvider::class),
            ]);
        });

        $this->app->singleton(PosDispatcher::class, function (): PosDispatcher {
            // Adapters register here as they are built (Square first, then Clover),
            // keyed by PosProviderName value.
            return new PosDispatcher([]);
        });
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
        Event::listen(Logout::class, PurgeUserSessionsOnLogout::class);

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

<?php

namespace App\Providers;

use App\Enums\DeliveryProviderName;
use App\Enums\PosProviderName;
use App\Listeners\MergeGuestCartOnLogin;
use App\Listeners\PurgeUserSessionsOnLogout;
use App\Models\ApiKey;
use App\Models\Campaign;
use App\Models\DeliveryAssignment;
use App\Models\ItemTemplate;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Observers\DeliveryAssignmentObserver;
use App\Observers\MenuItemObserver;
use App\Observers\RestaurantObserver;
use App\Services\Campaigns\CampaignContentReviewer;
use App\Services\Campaigns\CampaignMailer;
use App\Services\Delivery\DeliveryDispatcher;
use App\Services\Delivery\DoorDash\DoorDashProvider;
use App\Services\Delivery\SelfDeliveryProvider;
use App\Services\Delivery\UberDirect\UberDirectProvider;
use App\Services\MarketingConsentService;
use App\Services\Pos\Clover\CloverPosProvider;
use App\Services\Pos\PosDispatcher;
use App\Services\Pos\Square\SquarePosProvider;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
            // Adapters register here as they are built (Uber Direct first, then
            // DoorDash Drive), keyed by DeliveryProviderName value.
            return new DeliveryDispatcher([
                DeliveryProviderName::Self->value => $app->make(SelfDeliveryProvider::class),
                DeliveryProviderName::DoorDash->value => $app->make(DoorDashProvider::class),
                DeliveryProviderName::Uber->value => $app->make(UberDirectProvider::class),
            ]);
        });

        $this->app->singleton(CampaignContentReviewer::class, function (): CampaignContentReviewer {
            // Keyless (local dev, tests) the reviewer returns no verdict and
            // held campaigns fall through to the human super-admin console.
            return new CampaignContentReviewer(config('services.anthropic.api_key'));
        });

        $this->app->singleton(CampaignMailer::class, function ($app): CampaignMailer {
            // Keyless (local dev, tests) the mailer logs batches instead of
            // sending — the campaign pipeline stays runnable without Resend.
            return new CampaignMailer(
                $app->make(MarketingConsentService::class),
                config('services.resend.key'),
            );
        });

        $this->app->singleton(PosDispatcher::class, function ($app): PosDispatcher {
            // Adapters register here as they are built (Square first, then Clover),
            // keyed by PosProviderName value.
            return new PosDispatcher([
                PosProviderName::Square->value => $app->make(SquarePosProvider::class),
                PosProviderName::Clover->value => $app->make(CloverPosProvider::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // Resend's default API rate limit; applied to SendCampaignBatch jobs
        // (each job is one batch API call) whenever a real key is configured.
        RateLimiter::for('campaign-batches', fn (): Limit => Limit::perSecond(2));

        // Public API (/api/v1): per principal when authenticated, per IP
        // otherwise. The auth endpoints layer stricter, purpose-specific
        // limiters on top; operator routes get a roomier bucket of their own.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(120)
            ->by($this->limiterKey($request)));

        RateLimiter::for('api-operator', fn (Request $request): Limit => Limit::perMinute(300)
            ->by($this->limiterKey($request)));

        // Bearer `pfk_…` keys for the operator API and the MCP server.
        Auth::viaRequest('api-key', fn (Request $request): ?ApiKey => ApiKey::authenticate($request->bearerToken()));

        RateLimiter::for('api-auth', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        RateLimiter::for('api-checkout', fn (Request $request): Limit => Limit::perMinute(10)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('api-two-factor', fn (Request $request): Limit => Limit::perMinute(5)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        Restaurant::observe(RestaurantObserver::class);
        MenuItem::observe(MenuItemObserver::class);
        DeliveryAssignment::observe(DeliveryAssignmentObserver::class);

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

        Route::bind('campaign', function ($value) {
            $restaurant = request()->route('restaurant');
            $restaurantId = $restaurant instanceof Restaurant ? $restaurant->id : null;

            $campaign = Campaign::withoutTenantScope()
                ->when($restaurantId, fn ($q) => $q->where('restaurant_id', $restaurantId))
                ->where('id', $value)
                ->first();

            if (! $campaign || ($restaurantId && $campaign->restaurant_id !== $restaurantId)) {
                throw new NotFoundHttpException;
            }

            return $campaign;
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
     * Rate-limit bucket for a request: the principal's class and id, so a
     * user and an API key that happen to share an id never share a bucket.
     */
    protected function limiterKey(Request $request): string
    {
        $principal = $request->user();

        return $principal !== null
            ? class_basename($principal).':'.$principal->getAuthIdentifier()
            : (string) $request->ip();
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
            ? Password::min(10)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

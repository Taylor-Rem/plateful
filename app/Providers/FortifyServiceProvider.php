<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            LoginResponse::class,
            \App\Http\Responses\LoginResponse::class,
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    private function adminHost(): string
    {
        return 'admin.'.config('platform.primary_domain');
    }

    private function isAdminHost(Request $request): bool
    {
        return $request->getHost() === $this->adminHost();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = (string) $request->input(Fortify::username());
            $password = (string) $request->input('password');

            if ($this->isAdminHost($request)) {
                // Admin host: any user who is super admin OR a member of any
                // restaurant via restaurant_user pivot. Tenant scoping (which
                // restaurant they can manage) is enforced downstream.
                $user = User::query()
                    ->where('email', $email)
                    ->where(function ($q) {
                        $q->where('is_super_admin', true)
                            ->orWhereHas('restaurants');
                    })
                    ->first();
            } else {
                // Tenant host: any Plateful account. The tenant context only
                // affects what they see (orders, loyalty), not whether they
                // can log in. This is the "Shopify pattern" — one account
                // works at every Plateful restaurant.
                $tenant = app(CurrentTenant::class);
                if (! $tenant->check()) {
                    return null;
                }

                $user = User::query()->where('email', $email)->first();
            }

            if ($user && Hash::check($password, $user->password)) {
                return $user;
            }

            return null;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(function (Request $request) {
            if ($this->isAdminHost($request)) {
                return Inertia::render('Admin/Login', [
                    'canResetPassword' => Features::enabled(Features::resetPasswords()),
                    'status' => $request->session()->get('status'),
                ]);
            }

            return Inertia::render('auth/Login', [
                'canResetPassword' => Features::enabled(Features::resetPasswords()),
                'canRegister' => Features::enabled(Features::registration()),
                'status' => $request->session()->get('status'),
            ]);
        });

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        // Admin host has no public registration. Tenant hosts use the customer register view.
        Fortify::registerView(function (Request $request) {
            if ($this->isAdminHost($request)) {
                abort(404);
            }

            $tenant = app(CurrentTenant::class)->get();

            return Inertia::render('auth/Register', [
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
                'restaurantName' => $tenant?->name,
            ]);
        });

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}

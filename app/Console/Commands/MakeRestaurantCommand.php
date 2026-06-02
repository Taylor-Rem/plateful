<?php

namespace App\Console\Commands;

use App\Enums\RestaurantRole;
use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use App\Models\RestaurantHour;
use App\Models\User;
use App\Support\Menus\MenuBuilder;
use App\Support\Menus\MenuPresets;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

#[Signature('make:restaurant
    {name : The restaurant display name}
    {--subdomain= : Subdomain to use (derived from the name when omitted)}
    {--menu= : Cuisine preset to seed (random when omitted). One of: '.self::CUISINE_LIST.'}
    {--stop= : Stop early. "onboarding" creates only the restaurant, owner, and membership so the onboarding checklist is exercisable}')]
#[Description('Scaffold a restaurant end-to-end for local development.')]
class MakeRestaurantCommand extends Command
{
    private const CUISINE_LIST = 'italian, mexican, american, sushi, thai';

    private const DEFAULT_PASSWORD = 'password';

    public function handle(MenuBuilder $menuBuilder): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('make:restaurant is a development-only command and refuses to run outside local/testing.');

            return self::FAILURE;
        }

        $name = trim((string) $this->argument('name'));
        $subdomain = Str::slug($this->option('subdomain') ?: $name);
        $stopAtOnboarding = $this->option('stop') === 'onboarding';

        if ($subdomain === '') {
            $this->error('Could not derive a subdomain from the name; pass --subdomain explicitly.');

            return self::INVALID;
        }

        $cuisine = $this->resolveCuisine();
        if ($cuisine === null) {
            return self::INVALID;
        }

        if (Restaurant::query()->where('subdomain', $subdomain)->exists()) {
            $this->error("A restaurant with the subdomain [{$subdomain}] already exists.");

            return self::FAILURE;
        }

        $ownerEmail = "owner@{$subdomain}.test";
        if (User::query()->where('email', $ownerEmail)->exists()) {
            $this->error("A user with the email [{$ownerEmail}] already exists.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($name, $subdomain, $ownerEmail, $cuisine, $stopAtOnboarding, $menuBuilder, &$restaurant, &$owner): void {
            $restaurant = Restaurant::create([
                'name' => $name,
                'subdomain' => $subdomain,
                'description' => "{$name} — seeded for local development.",
                'email' => "hello@{$subdomain}.test",
                'phone' => '555-010-0000',
                'street' => '1 Test Street',
                'city' => 'Testville',
                'state' => 'NY',
                'postal_code' => '10001',
                'country' => 'US',
                'timezone' => 'America/New_York',
                'is_active' => true,
                'status' => RestaurantStatus::Approved,
                'approved_at' => now(),
                'tax_rate_percent' => 8.25,
            ]);

            $owner = new User;
            $owner->name = "{$name} Owner";
            $owner->email = $ownerEmail;
            $owner->password = Hash::make(self::DEFAULT_PASSWORD);
            $owner->is_super_admin = false;
            $owner->email_verified_at = now();
            $owner->save();

            $owner->restaurants()->attach($restaurant->id, ['role' => RestaurantRole::Admin->value]);

            if ($stopAtOnboarding) {
                return;
            }

            $this->seedHours($restaurant);
            $menuBuilder->build($restaurant, $cuisine);
            $this->stubStripe($restaurant);
            $this->goLive($restaurant);
        });

        $this->report($restaurant, $owner, $ownerEmail, $cuisine, $stopAtOnboarding);

        return self::SUCCESS;
    }

    /**
     * Resolve the cuisine preset from --menu, or pick a random one. Returns
     * null on an invalid explicit value.
     */
    private function resolveCuisine(): ?string
    {
        $menu = $this->option('menu');

        if ($menu === null || $menu === '') {
            $cuisines = MenuPresets::cuisines();

            return $cuisines[array_rand($cuisines)];
        }

        $menu = strtolower($menu);

        if (! MenuPresets::has($menu)) {
            $this->error("Unknown cuisine [{$menu}]. Available: ".implode(', ', MenuPresets::cuisines()).'.');

            return null;
        }

        return $menu;
    }

    private function seedHours(Restaurant $restaurant): void
    {
        for ($day = 0; $day <= 6; $day++) {
            RestaurantHour::create([
                'restaurant_id' => $restaurant->id,
                'day_of_week' => $day,
                'opens_at' => '09:00:00',
                'closes_at' => '21:00:00',
                'position' => 0,
            ]);
        }
    }

    private function stubStripe(Restaurant $restaurant): void
    {
        $restaurant->forceFill([
            'stripe_account_id' => "acct_demo_{$restaurant->subdomain}",
            'stripe_account_status' => Restaurant::STRIPE_ENABLED,
        ])->save();
    }

    private function goLive(Restaurant $restaurant): void
    {
        $restaurant->forceFill([
            'status' => RestaurantStatus::Active,
            'is_active' => true,
            'onboarding_completed_at' => now(),
        ])->save();
    }

    private function report(Restaurant $restaurant, User $owner, string $ownerEmail, string $cuisine, bool $stopAtOnboarding): void
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'plateful.test';

        $state = $stopAtOnboarding
            ? 'Approved — onboarding checklist left incomplete (no hours, menu, or Stripe)'
            : 'Active — live, Stripe stubbed, ready to take orders';

        $this->info("Created restaurant [{$restaurant->name}] (id={$restaurant->id}).");
        $this->newLine();

        $this->table(['Field', 'Value'], [
            ['Status', $state],
            ['Menu', $stopAtOnboarding ? '(none)' : $cuisine],
            ['Storefront', "https://{$restaurant->subdomain}.{$host}"],
            ['Admin', "https://admin.{$host}/{$restaurant->subdomain}"],
            ['Owner login', $ownerEmail],
            ['Owner password', self::DEFAULT_PASSWORD],
        ]);

        if ($stopAtOnboarding) {
            $this->newLine();
            $this->comment('Log in as the owner and walk the onboarding checklist (hours → menu → Stripe → go live).');
        }
    }
}

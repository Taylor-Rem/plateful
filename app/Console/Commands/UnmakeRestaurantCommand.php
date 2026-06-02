<?php

namespace App\Console\Commands;

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('unmake:restaurant
    {subdomain : The subdomain of the restaurant to remove}
    {--hard : Permanently delete the restaurant and all related data (orders, menu, members — cascades)}
    {--force : Skip the confirmation prompt when hard-deleting}')]
#[Description('Deactivate (default) or hard-delete a restaurant created for local development.')]
class UnmakeRestaurantCommand extends Command
{
    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            $this->error('unmake:restaurant is a development-only command and refuses to run outside local/testing.');

            return self::FAILURE;
        }

        $subdomain = (string) $this->argument('subdomain');

        $restaurant = Restaurant::query()->where('subdomain', $subdomain)->first();

        if ($restaurant === null) {
            $this->error("No restaurant found with the subdomain [{$subdomain}].");

            return self::FAILURE;
        }

        if ($this->option('hard')) {
            return $this->hardDelete($restaurant);
        }

        return $this->deactivate($restaurant);
    }

    private function deactivate(Restaurant $restaurant): int
    {
        $restaurant->forceFill([
            'is_active' => false,
            'status' => RestaurantStatus::Suspended,
            'suspended_at' => now(),
            'suspension_reason' => 'Deactivated via unmake:restaurant.',
        ])->save();

        $this->info("Deactivated [{$restaurant->name}] — hidden from the storefront, all data preserved.");
        $this->comment('Pass --hard to permanently delete it and everything it owns.');

        return self::SUCCESS;
    }

    private function hardDelete(Restaurant $restaurant): int
    {
        if (! $this->option('force')
            && ! $this->confirm("Permanently delete [{$restaurant->name}] and ALL of its orders, menu, and members?")) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $name = $restaurant->name;
        $restaurant->delete();

        $this->info("Deleted [{$name}] and all related data (cascade).");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\RestaurantStatus;
use App\Models\Restaurant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('platform:suspend-expired-trials')]
#[Description('Suspends restaurants whose free trial has ended without an active subscription.')]
class SuspendExpiredTrials extends Command
{
    public function handle(): int
    {
        $subscriptionType = (string) config('platform.billing.subscription_type', 'default');

        // Anything Active whose trial has run out and that has not started a
        // paying subscription needs to be paused until they subscribe.
        $candidates = Restaurant::query()
            ->where('status', RestaurantStatus::Active)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<', now())
            ->get();

        $suspended = 0;

        foreach ($candidates as $restaurant) {
            if ($restaurant->subscribed($subscriptionType)) {
                continue;
            }

            $restaurant->update([
                'status' => RestaurantStatus::Suspended,
                'suspended_at' => now(),
                'suspension_reason' => 'Trial expired without an active subscription.',
            ]);

            $suspended++;
            $this->line("Suspended {$restaurant->name} ({$restaurant->subdomain}).");
        }

        $this->info("Suspended {$suspended} restaurant(s).");

        return self::SUCCESS;
    }
}

<?php

use App\Enums\RestaurantStatus;
use App\Models\Campaign;
use App\Models\Restaurant;
use App\Models\User;

require_once __DIR__.'/../Admin/AdminOrderTestHelpers.php';
require_once __DIR__.'/../Admin/CustomerTestHelpers.php';

if (! function_exists('liveRestaurant')) {
    /**
     * A restaurant that passes the campaign sending gate. Established
     * (campaigns-approved) by default; pass false to model a restaurant
     * still subject to the first-campaign review queue.
     */
    function liveRestaurant(string $sub = 'marcos', bool $campaignsApproved = true): Restaurant
    {
        $r = adminOrderRestaurant($sub);
        $r->forceFill([
            'status' => RestaurantStatus::Active,
            'is_active' => true,
            'stripe_account_status' => Restaurant::STRIPE_ENABLED,
            'campaigns_approved_at' => $campaignsApproved ? now() : null,
        ])->save();

        return $r;
    }
}

if (! function_exists('campaign')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function campaign(Restaurant $r, array $overrides = []): Campaign
    {
        return Campaign::create(array_merge([
            'restaurant_id' => $r->id,
            'subject' => 'Slow Tuesday: half-price pies',
            'preheader' => 'This Tuesday only',
            'headline' => 'Half-price pies this Tuesday',
            'body' => "Come hungry.\nLeave happy.",
            'offer_callout' => '50% off all pies',
            'audience_filter' => ['type' => 'all'],
            'status' => 'scheduled',
        ], $overrides));
    }
}

if (! function_exists('optedInCustomer')) {
    /**
     * @param  array<string, mixed>  $pivotOverrides
     */
    function optedInCustomer(Restaurant $r, string $name, string $email, array $pivotOverrides = []): User
    {
        $u = customerUser($name, $email);
        customerPivot($r, $u, array_merge([
            'marketing_email_opted_in_at' => now()->subDay(),
        ], $pivotOverrides));

        return $u;
    }
}

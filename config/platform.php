<?php

return [
    'primary_domain' => env('PLATFORM_PRIMARY_DOMAIN', 'plateful.test'),
    'admin_subdomain' => env('PLATFORM_ADMIN_SUBDOMAIN', 'admin'),

    /*
    |---------------------------------------------------------------------------
    | Default Application Fee Percent
    |---------------------------------------------------------------------------
    |
    | The platform fee rate (percent of the food subtotal) applied to a
    | restaurant at CREATION time. Each restaurant keeps the rate it was
    | created with — changing this value only governs future sign-ups and
    | never alters an existing restaurant's stored rate (grandfathering).
    | Per-restaurant overrides are set by super admins in the console.
    |
    */
    'default_application_fee_percent' => (float) env('PLATFORM_DEFAULT_APPLICATION_FEE_PERCENT', 4.00),

    /*
    |---------------------------------------------------------------------------
    | Admin Notification Email
    |---------------------------------------------------------------------------
    |
    | Where platform notifications (new restaurant signups, etc.) are sent.
    | Falls back to the configured mail "from" address.
    |
    */
    'admin_notification_email' => env('PLATFORM_ADMIN_NOTIFICATION_EMAIL'),

    /*
    |---------------------------------------------------------------------------
    | Reserved Subdomains
    |---------------------------------------------------------------------------
    |
    | Subdomains that must not be used as a tenant subdomain. These are
    | enforced when a super admin creates a new restaurant.
    |
    */
    'reserved_subdomains' => [
        'admin',
        'api',
        'app',
        'auth',
        'cdn',
        'dashboard',
        'dev',
        'docs',
        'help',
        'home',
        'imap',
        'mail',
        'marketing',
        'pop',
        'smtp',
        'staging',
        'static',
        'status',
        'stripe',
        'support',
        'test',
        'webhook',
        'webhooks',
        'www',
    ],

    /*
    |---------------------------------------------------------------------------
    | Available Timezones
    |---------------------------------------------------------------------------
    |
    | Curated list of timezones offered when creating a restaurant.
    |
    */
    /*
    |---------------------------------------------------------------------------
    | Loyalty
    |---------------------------------------------------------------------------
    |
    | How many loyalty points a customer earns per whole dollar of order
    | subtotal when the order is marked completed.
    |
    */
    'loyalty' => [
        'points_per_dollar' => 1,
    ],

    'timezones' => [
        'America/New_York',
        'America/Chicago',
        'America/Denver',
        'America/Phoenix',
        'America/Los_Angeles',
        'America/Anchorage',
        'Pacific/Honolulu',
    ],
];

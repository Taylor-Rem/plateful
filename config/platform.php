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
    | Revenue Shares
    |---------------------------------------------------------------------------
    |
    | How the platform fee Plateful RETAINS from each order (the application
    | fee) is attributed across roles. These are shares of Plateful's take —
    | NOT of the restaurant's sales — and MUST sum to 100. Attribution only:
    | Plateful still collects the whole fee via Stripe; these percentages drive
    | the internal earnings ledger used to pay role-holders out of band.
    |
    | Keys are RevenueRole values. Adding a future bucket (e.g. 'company' to
    | fund salaried departments) is a matter of adding a key here and a role to
    | the enum — no schema change. Recruiter is tracked but currently unpaid.
    |
    */
    'revenue_shares' => [
        'founder' => 10,
        'recruiter' => 0,
        'overseer' => 90,
    ],

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

    'delivery' => [
        /*
         * How long a courier-network delivery may sit authorized-but-uncaptured
         * while the provider looks for a driver. Past this, the hold is released
         * and the order is cancelled.
         *
         * Generous on purpose: assignment usually resolves in a couple of
         * minutes, and voiding a delivery that was about to be fine is a worse
         * error than making the customer wait longer to hear bad news.
         */
        'courier_deadline_minutes' => (int) env('DELIVERY_COURIER_DEADLINE_MINUTES', 10),
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

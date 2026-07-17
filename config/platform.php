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
    | Commission Monthly Cap
    |---------------------------------------------------------------------------
    |
    | The most commission (in cents) Plateful retains from one restaurant in a
    | single calendar month. Snapshotted onto each restaurant at creation
    | (nullable `restaurants.commission_monthly_cap_cents` overrides it), so
    | changing this default never alters an existing restaurant's cap
    | (grandfathering), exactly like the fee percent above. Default $249/mo;
    | breakeven is roughly $6,225/mo of food at the 4% rate.
    |
    */
    'commission_monthly_cap_cents' => (int) env('PLATFORM_COMMISSION_MONTHLY_CAP_CENTS', 24900),

    /*
    |---------------------------------------------------------------------------
    | Stripe Variable Rate
    |---------------------------------------------------------------------------
    |
    | Stripe's variable per-charge percentage (as a fraction, e.g. 0.029 for
    | 2.9%). Used from Session 4b to gross up the customer-facing delivery fee
    | so the restaurant bears no Stripe cost on the delivery line. The fixed 30¢
    | is deliberately excluded — it is the restaurant's normal card cost.
    |
    */
    'stripe_variable_rate' => (float) env('PLATFORM_STRIPE_VARIABLE_RATE', 0.029),

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

        'doordash' => [
            /*
             * DoorDash Drive serves sandbox and production from the same host;
             * the environment is a property of the credentials, not the URL.
             * Overridable only so tests and any future host move have a seam.
             */
            'base_url' => env('DOORDASH_BASE_URL', 'https://openapi.doordash.com'),

            /*
             * DoorDash quote responses carry no expiry field; the docs say a
             * quote must be accepted within ~5 minutes. We stamp a synthetic
             * expiry this many minutes out so DeliveryDispatcher::quoteForDispatch
             * re-quotes proactively rather than accepting a stale one.
             */
            'quote_accept_window_minutes' => (int) env('DOORDASH_QUOTE_ACCEPT_WINDOW_MINUTES', 5),
        ],
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

<?php

return [
    'primary_domain' => env('PLATFORM_PRIMARY_DOMAIN', 'plateful.test'),
    'admin_subdomain' => env('PLATFORM_ADMIN_SUBDOMAIN', 'admin'),

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

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'anthropic' => [
        'api_key' => env('CLAUDE_API_KEY'),
    ],

    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Country the Express connected accounts are created under.
        'connect_country' => env('STRIPE_CONNECT_COUNTRY', 'US'),
    ],

    'square' => [
        'application_id' => env('SQUARE_APPLICATION_ID'),
        'application_secret' => env('SQUARE_APPLICATION_SECRET'),
        // `sandbox` or `production` — selects the Square API host.
        'environment' => env('SQUARE_ENVIRONMENT', 'sandbox'),
        // Square registers a single OAuth redirect URI. It must resolve on the
        // admin host (where the owner's session lives) because the callback
        // writes credentials on their behalf; the restaurant is carried in the
        // OAuth `state`, not the URL.
        'redirect' => env('SQUARE_REDIRECT_URI'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // The Google OAuth client registers a single redirect URI on the
        // platform host (Google forbids wildcard subdomains), so the callback
        // always resolves on the root domain regardless of the storefront the
        // customer started from.
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

];

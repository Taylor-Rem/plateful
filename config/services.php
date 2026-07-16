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
        // Note: STRIPE_KEY (publishable) is not read via config anywhere —
        // checkout is Stripe-hosted, so no Stripe.js runs in the browser.
        // scripts/cloud-check.php still verifies the env var is set.
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

    'clover' => [
        'app_id' => env('CLOVER_APP_ID'),
        'app_secret' => env('CLOVER_APP_SECRET'),
        // `sandbox` or `production` — selects both the OAuth authorize host and
        // the API host (Clover splits the two). North America only for now.
        'environment' => env('CLOVER_ENVIRONMENT', 'sandbox'),
        // Clover registers a single OAuth redirect URI. Like Square it must
        // resolve on the admin host (where the owner's session lives); the
        // restaurant travels in the OAuth `state`, not the URL.
        'redirect' => env('CLOVER_REDIRECT_URI'),
    ],

    'uber_direct' => [
        // Uber Direct credentials are PER-RESTAURANT and live encrypted in
        // `delivery_integrations` — each restaurant holds its own Uber account
        // and Uber bills them directly, so there is no app-level credential the
        // way Square and Clover have one.
        //
        // These are sandbox credentials for local development and the opt-in
        // live sandbox test. Production resolves credentials ONLY from the
        // integration row; nothing here is a fallback.
        //
        // Deliberately no `environment` key: unlike Square/Clover, Uber Direct
        // serves test and production from the same host (api.uber.com). Test
        // mode is a property of the credentials, not the URL.
        'sandbox_client_id' => env('UBER_DIRECT_SANDBOX_CLIENT_ID'),
        'sandbox_client_secret' => env('UBER_DIRECT_SANDBOX_CLIENT_SECRET'),
        'sandbox_customer_id' => env('UBER_DIRECT_SANDBOX_CUSTOMER_ID'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // The Google OAuth client registers a single redirect URI on the
        // platform host (Google forbids wildcard subdomains), so the callback
        // always resolves on the root domain regardless of the storefront the
        // customer started from.
        'redirect' => env('GOOGLE_REDIRECT_URI'),

        // Places autocomplete for delivery addresses. A DIFFERENT credential
        // from the OAuth client above — same Google Cloud project, but an API
        // key rather than an OAuth client, and the Places API must be enabled
        // on it.
        //
        // Server-side and IP-restricted, never a browser key: we proxy Places
        // through the backend rather than calling it from the storefront. A
        // browser key is protected only by an HTTP-referrer allowlist, and every
        // custom domain we onboard would be another entry to maintain. Keeping
        // it here also means it never reaches the client at all.
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

];

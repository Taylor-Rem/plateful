<?php

/**
 * scripts/cloud-check.php is a standalone script (no framework, no autoloader)
 * whose check logic is pure over the env-var map Cloud returns. Requiring the
 * file defines the functions without running main(), so the checks can be
 * exercised here against hand-built environments without a Cloud token.
 */
require_once __DIR__.'/../../scripts/cloud-check.php';

/**
 * Every var a fully configured production environment carries, with
 * placeholder values in the right shape (never real secrets).
 *
 * @return array<string, string>
 */
function fullyConfiguredCloudEnv(): array
{
    return [
        'APP_ENV' => 'production',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:placeholder',
        'APP_URL' => 'https://plateful.fyi',
        'LOG_CHANNEL' => 'stderr',
        'PLATFORM_PRIMARY_DOMAIN' => 'plateful.fyi',
        'PLATFORM_ADMIN_SUBDOMAIN' => 'admin',
        'PLATFORM_BOOKING_URL' => 'https://cal.com/example/intro',
        'STRIPE_KEY' => 'pk_live_placeholder',
        'STRIPE_SECRET' => 'sk_live_placeholder',
        'STRIPE_WEBHOOK_SECRET' => 'whsec_placeholder',
        'STRIPE_CONNECT_COUNTRY' => 'US',
        'SQUARE_ENVIRONMENT' => 'production',
        'SQUARE_APPLICATION_ID' => 'sq0idp-placeholder',
        'SQUARE_APPLICATION_SECRET' => 'sq0csp-placeholder',
        'SQUARE_REDIRECT_URI' => 'https://admin.plateful.fyi/pos/square/callback',
        'CLOVER_ENVIRONMENT' => 'production',
        'CLOVER_APP_ID' => 'clover-app-placeholder',
        'CLOVER_APP_SECRET' => 'clover-secret-placeholder',
        'CLOVER_REDIRECT_URI' => 'https://admin.plateful.fyi/pos/clover/callback',
        'CLAUDE_API_KEY' => 'sk-ant-placeholder',
        'GOOGLE_MAPS_API_KEY' => 'AIza-placeholder',
        'GOOGLE_CLIENT_ID' => 'google-client-placeholder',
        'GOOGLE_CLIENT_SECRET' => 'google-secret-placeholder',
        'GOOGLE_REDIRECT_URI' => 'https://plateful.fyi/auth/google/callback',
        'UBER_DIRECT_CLIENT_ID' => 'uber-client-placeholder',
        'UBER_DIRECT_CLIENT_SECRET' => 'uber-secret-placeholder',
        'UBER_DIRECT_CUSTOMER_ID' => 'uber-customer-placeholder',
        'UBER_DIRECT_WEBHOOK_SECRET' => 'uber-webhook-placeholder',
        'DOORDASH_DEVELOPER_ID' => 'dd-dev-placeholder',
        'DOORDASH_KEY_ID' => 'dd-key-placeholder',
        'DOORDASH_SIGNING_SECRET' => 'dd-signing-placeholder',
        'DOORDASH_WEBHOOK_SECRET' => 'dd-webhook-placeholder',
        'QUEUE_CONNECTION' => 'database',
        'MAIL_MAILER' => 'resend',
        'MAIL_FROM_ADDRESS' => 'service@plateful.fyi',
        'MAIL_FROM_ORDERS_ADDRESS' => 'orders@plateful.fyi',
        'MAIL_FROM_SERVICE_ADDRESS' => 'service@plateful.fyi',
        'MAIL_FROM_SUPPORT_ADDRESS' => 'support@plateful.fyi',
        'MARKETING_MAIL_DOMAIN' => 'platefuloffers.fyi',
        'RESEND_API_KEY' => 're_placeholder',
        'RESEND_WEBHOOK_SECRET' => 'whsec_resend_placeholder',
        'SENTRY_LARAVEL_DSN' => 'https://publickey@sentry.example.com/1',
        'FILESYSTEM_DISK' => 's3',
        'AWS_ACCESS_KEY_ID' => 'AKIA-placeholder',
        'AWS_BUCKET' => 'plateful-media',
    ];
}

/**
 * @param  array<string, string>  $vars
 * @param  list<array<string, mixed>>|null  $logs
 * @return array{0: CloudCheckReport, 1: string}
 */
function runCloudCheck(array $vars, ?array $logs = []): array
{
    ob_start();
    $report = run_checks($vars, $logs);
    print_summary($report);
    $output = (string) ob_get_clean();

    return [$report, $output];
}

it('reports a fully configured production environment as ready', function () {
    [$report, $output] = runCloudCheck(fullyConfiguredCloudEnv());

    expect($report->ready())->toBeTrue()
        ->and($report->blockers)->toBe([])
        ->and($report->warnings)->toBe([])
        ->and($output)->toContain('READY: no launch blockers');
});

it('lists every launch blocker on an empty environment', function () {
    [$report, $output] = runCloudCheck([]);

    expect($report->ready())->toBeFalse()
        ->and($report->blockerKeys())->toContain(
            'PLATFORM_PRIMARY_DOMAIN',
            'STRIPE_KEY',
            'STRIPE_SECRET',
            'STRIPE_WEBHOOK_SECRET',
            'SQUARE_ENVIRONMENT',
            'CLOVER_ENVIRONMENT',
            'CLOVER_APP_ID',
            'CLAUDE_API_KEY',
            'GOOGLE_MAPS_API_KEY',
            'UBER_DIRECT_CLIENT_ID',
            'UBER_DIRECT_WEBHOOK_SECRET',
            'MAIL_MAILER',
            'RESEND_API_KEY',
            'SENTRY_LARAVEL_DSN',
        )
        ->and($output)->toContain('NOT READY');
});

it('warns rather than blocks on keys Laravel Cloud injects without listing', function () {
    $vars = fullyConfiguredCloudEnv();
    unset($vars['APP_ENV'], $vars['APP_KEY'], $vars['APP_DEBUG'], $vars['FILESYSTEM_DISK'], $vars['AWS_BUCKET']);

    [$report, $output] = runCloudCheck($vars);

    expect($report->ready())->toBeTrue()
        ->and($report->warningKeys())->toContain('APP_ENV', 'APP_KEY', 'APP_DEBUG', 'FILESYSTEM_DISK', 'AWS_BUCKET', 'media disk')
        ->and($output)->toContain('confirm in the dashboard');
});

it('still blocks when a Cloud-injected key is present with the wrong value', function () {
    $vars = fullyConfiguredCloudEnv();
    $vars['APP_DEBUG'] = 'true';
    $vars['FILESYSTEM_DISK'] = 'local';

    [$report] = runCloudCheck($vars);

    expect($report->blockerKeys())->toContain('APP_DEBUG', 'FILESYSTEM_DISK', 'media disk');
});

it('treats a sandbox Clover environment as a launch blocker', function () {
    $vars = fullyConfiguredCloudEnv();
    $vars['CLOVER_ENVIRONMENT'] = 'sandbox';

    [$report, $output] = runCloudCheck($vars);

    expect($report->blockerKeys())->toBe(['CLOVER_ENVIRONMENT'])
        ->and($output)->toContain('CLOVER_ENVIRONMENT')
        ->and($output)->toContain('[expected production]');
});

it('reports the effective media disk through the FILESYSTEM_DISK fallback', function () {
    $vars = fullyConfiguredCloudEnv();
    $vars['FILESYSTEM_DISK'] = 'local';
    unset($vars['MEDIA_DISK']);

    [$report, $output] = runCloudCheck($vars);

    expect($output)->toContain('local (via FILESYSTEM_DISK fallback) [expected s3')
        ->and($report->blockerKeys())->toContain('media disk');

    $vars['MEDIA_DISK'] = 's3';
    [$report, $output] = runCloudCheck($vars);

    expect($output)->toContain('s3 (via MEDIA_DISK) [ok]')
        ->and($report->blockerKeys())->not->toContain('media disk');
});

it('reports Stripe keys as LIVE or TEST by prefix and never prints the value', function () {
    $vars = fullyConfiguredCloudEnv();
    $vars['STRIPE_SECRET'] = 'sk_test_supersecretvalue';

    [$report, $output] = runCloudCheck($vars);

    expect($output)->toContain('STRIPE_SECRET')
        ->and($output)->toContain('TEST (sk_…) [still test mode]')
        ->and($output)->not->toContain('supersecretvalue')
        ->and($output)->not->toContain('pk_live_placeholder')
        ->and($report->blockerKeys())->toBe(['STRIPE_SECRET']);
});

it('never prints present-only secret values', function () {
    [, $output] = runCloudCheck(fullyConfiguredCloudEnv());

    foreach (['sk-ant-placeholder', 'uber-secret-placeholder', 're_placeholder', 'clover-secret-placeholder', 'dd-signing-placeholder'] as $secret) {
        expect($output)->not->toContain($secret);
    }
});

it('keeps missing DoorDash credentials a warning while Uber Direct covers launch', function () {
    $vars = fullyConfiguredCloudEnv();
    unset($vars['DOORDASH_DEVELOPER_ID'], $vars['DOORDASH_KEY_ID'], $vars['DOORDASH_SIGNING_SECRET'], $vars['DOORDASH_WEBHOOK_SECRET']);

    [$report] = runCloudCheck($vars);

    expect($report->ready())->toBeTrue()
        ->and($report->warningKeys())->toContain('DOORDASH_DEVELOPER_ID', 'DOORDASH_WEBHOOK_SECRET');
});

it('blocks on a local-only primary domain and a non-https app url', function () {
    $vars = fullyConfiguredCloudEnv();
    $vars['PLATFORM_PRIMARY_DOMAIN'] = 'plateful.test';
    $vars['APP_URL'] = 'http://plateful.fyi';

    [$report] = runCloudCheck($vars);

    expect($report->blockerKeys())->toContain('PLATFORM_PRIMARY_DOMAIN', 'APP_URL');
});

it('warns when SESSION_DOMAIN is set, since tenant hosts must keep separate cookies', function () {
    $vars = fullyConfiguredCloudEnv();
    $vars['SESSION_DOMAIN'] = '.plateful.fyi';

    [$report] = runCloudCheck($vars);

    expect($report->ready())->toBeTrue()
        ->and($report->warningKeys())->toContain('SESSION_DOMAIN');
});

it('surfaces recent application errors as a warning', function () {
    $logs = [
        ['level' => 'info', 'message' => 'fine'],
        ['level' => 'error', 'message' => 'Something broke', 'logged_at' => '2026-09-14T10:00:00Z'],
    ];

    [$report, $output] = runCloudCheck(fullyConfiguredCloudEnv(), $logs);

    expect($output)->toContain('1 error/exception entries in the last 24h (of 2 log lines fetched)')
        ->and($output)->toContain('Something broke')
        ->and($report->warningKeys())->toContain('recent errors');
});

it('finds the Cloud token in the project .env, the environment, or the toolbelt key file', function () {
    $dir = sys_get_temp_dir().'/cloud-check-'.uniqid();
    mkdir($dir);
    file_put_contents($dir.'/.env', "APP_NAME=Plateful\nLARAVEL_CLOUD_TOKEN=\"from-dotenv\"\n");
    file_put_contents($dir.'/toolbelt', "export LARAVEL_CLOUD_TOKEN=from-toolbelt\n");
    file_put_contents($dir.'/empty', "LARAVEL_CLOUD_TOKEN=\n");

    expect(read_cloud_token($dir.'/.env', 'from-environment', $dir.'/toolbelt'))->toBe('from-dotenv')
        ->and(read_cloud_token($dir.'/missing', 'from-environment', $dir.'/toolbelt'))->toBe('from-environment')
        ->and(read_cloud_token($dir.'/missing', null, $dir.'/toolbelt'))->toBe('from-toolbelt')
        ->and(read_cloud_token($dir.'/empty', '', $dir.'/missing'))->toBeNull();

    foreach (['.env', 'toolbelt', 'empty'] as $file) {
        unlink($dir.'/'.$file);
    }
    rmdir($dir);
});

<?php

/**
 * Laravel Cloud production readiness checker (read-only).
 *
 * Reads LARAVEL_CLOUD_TOKEN, queries the Laravel Cloud API for the Plateful
 * production environment, and prints a readiness report that ends with the
 * list of launch blockers still open. Exit code 0 = no blockers, 1 = blockers
 * remain (so it can gate a release script), 2 = could not talk to Cloud.
 *
 * The token is looked up, in order, in: the project's .env
 * (LARAVEL_CLOUD_TOKEN=…), the LARAVEL_CLOUD_TOKEN environment variable, and
 * the toolbelt key file ~/.config/claude-tools/env — so it does not have to
 * live in the project directory at all.
 *
 * SECURITY: secret values are never printed. Stripe keys are reported only as
 * LIVE / TEST by prefix; API keys / passwords are reported present / absent.
 * The output is safe to paste back into a chat.
 *
 * Usage (from the project root):
 *   php scripts/cloud-check.php
 *   # or, if php isn't on your PATH:
 *   "$HOME/Library/Application Support/Herd/bin/php84" scripts/cloud-check.php
 *
 * Optional: pass an app slug and/or environment name if auto-detect picks wrong:
 *   php scripts/cloud-check.php plateful production
 *
 * Severity of each check (mirrors todo.md §0 "Launch blockers"):
 *   BLOCKER — launch cannot proceed (silent-fallback class: the app boots and
 *             quietly does the wrong thing — sandbox POS hosts, ephemeral
 *             uploads, no error reporting).
 *   WARN    — should be fixed, but launch does not hang on it, or the value is
 *             one Laravel Cloud injects itself and the API does not list.
 *
 * The check logic is plain functions over the env-var map so it can be unit
 * tested without a Cloud token (tests/Unit/CloudCheckTest.php); only main()
 * talks to the API.
 */
const BASE = 'https://cloud.laravel.com/api';

const SEVERITY_BLOCKER = 'blocker';

const SEVERITY_WARN = 'warn';

const SEVERITY_NONE = 'none';

/**
 * Env vars Laravel Cloud injects itself and does not list in the API's
 * environment_variables array, so "MISSING" there is not proof they are unset.
 * A missing one is reported as a warning with a "confirm in the dashboard"
 * note instead of a blocker; a PRESENT one with the wrong value still blocks.
 */
const CLOUD_INJECTED = [
    'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL',
    'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'FILESYSTEM_DISK', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION',
    'AWS_BUCKET', 'AWS_URL', 'AWS_ENDPOINT', 'AWS_USE_PATH_STYLE_ENDPOINT',
];

/** Collected findings for the summary at the end of the report. */
final class CloudCheckReport
{
    /** @var list<array{key: string, why: string}> */
    public array $blockers = [];

    /** @var list<array{key: string, why: string}> */
    public array $warnings = [];

    public function add(string $severity, string $key, string $why): void
    {
        if ($severity === SEVERITY_BLOCKER) {
            $this->blockers[] = ['key' => $key, 'why' => $why];
        } elseif ($severity === SEVERITY_WARN) {
            $this->warnings[] = ['key' => $key, 'why' => $why];
        }
    }

    public function ready(): bool
    {
        return $this->blockers === [];
    }

    /** @return list<string> */
    public function blockerKeys(): array
    {
        return array_column($this->blockers, 'key');
    }

    /** @return list<string> */
    public function warningKeys(): array
    {
        return array_column($this->warnings, 'key');
    }
}

// ---------------------------------------------------------------------------
// entry point (only when run directly — tests require this file without it)
// ---------------------------------------------------------------------------

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    exit(main($_SERVER['argv']));
}

/**
 * @param  list<string>  $argv
 */
function main(array $argv): int
{
    $root = dirname(__DIR__);
    $token = read_cloud_token($root.'/.env', getenv('LARAVEL_CLOUD_TOKEN') ?: null, toolbelt_env_path());
    if (! $token) {
        fwrite(STDERR, "ERROR: LARAVEL_CLOUD_TOKEN not found in .env, the environment, or ~/.config/claude-tools/env\n");

        return 2;
    }

    $wantApp = $argv[1] ?? null;   // optional app slug/name filter
    $wantEnv = $argv[2] ?? null;   // optional environment name filter

    line('Laravel Cloud readiness check');
    line(str_repeat('=', 40));

    // 1. Find the application.
    $apps = api(BASE.'/applications', $token)['data'] ?? [];
    if (! $apps) {
        fwrite(STDERR, "No applications returned. Is the token valid?\n");

        return 2;
    }
    $app = pick($apps, $wantApp, ['plateful']);
    if (! $app) {
        line('Could not auto-pick an app. Available:');
        foreach ($apps as $a) {
            line('  - '.($a['attributes']['slug'] ?? $a['id']).'  ('.($a['attributes']['name'] ?? '').')');
        }
        line('Re-run with:  php scripts/cloud-check.php <app-slug>');

        return 2;
    }
    $appId = $app['id'];
    line('App:         '.($app['attributes']['name'] ?? $appId));
    line('Repo:        '.($app['attributes']['repository']['full_name'] ?? 'n/a'));

    // 2. Find the environment.
    $envs = api(BASE.'/applications/'.$appId.'/environments', $token)['data'] ?? [];
    $env = pick($envs, $wantEnv, ['production', 'main']);
    if (! $env) {
        line('Could not auto-pick an environment. Available:');
        foreach ($envs as $e) {
            line('  - '.($e['attributes']['name'] ?? $e['id']));
        }
        line('Re-run with:  php scripts/cloud-check.php <app-slug> <env-name>');

        return 2;
    }
    $envId = $env['id'];
    line('Environment: '.($env['attributes']['name'] ?? $envId).'  (status: '.($env['attributes']['status'] ?? '?').')');
    line('');

    // 3. Pull env vars from the full environment resource.
    $full = api(BASE.'/environments/'.$envId, $token);
    $vars = [];
    foreach (($full['data']['attributes']['environment_variables'] ?? []) as $pair) {
        $vars[$pair['key']] = $pair['value'];
    }

    // 4. Recent application errors (last 24h).
    $from = gmdate('Y-m-d\TH:i:s\Z', time() - 82800); // 23h; API rejects "more than 1 day ago"
    $to = gmdate('Y-m-d\TH:i:s\Z', time());
    $logsUrl = BASE.'/environments/'.$envId.'/logs?type=application&from='.rawurlencode($from).'&to='.rawurlencode($to);
    $logs = api($logsUrl, $token)['data'] ?? null;

    $report = run_checks($vars, $logs);
    print_summary($report);

    return $report->ready() ? 0 : 1;
}

// ---------------------------------------------------------------------------
// the checks — pure over the env-var map, so they are unit-testable
// ---------------------------------------------------------------------------

/**
 * @param  array<string, string>  $vars  env-var key => value as Cloud lists them
 * @param  list<array<string, mixed>>|null  $logs  application log lines, null if unavailable
 */
function run_checks(array $vars, ?array $logs = null): CloudCheckReport
{
    $r = new CloudCheckReport;

    // ---- App config --------------------------------------------------------
    section('App');
    show('APP_ENV', $vars, $r, expect: 'production');
    show('APP_DEBUG', $vars, $r, expect: 'false', why: 'debug pages leak config and stack traces to customers');
    present('APP_KEY', $vars, $r);
    show('APP_URL', $vars, $r, why: 'signed URLs and mail links resolve against it');
    if (($vars['APP_URL'] ?? '') !== '' && ! str_starts_with($vars['APP_URL'], 'https://')) {
        note('APP_URL is not https:// — mixed-content warnings and insecure mail links', $r, SEVERITY_BLOCKER, 'APP_URL');
    }
    show('LOG_CHANNEL', $vars, $r, expect: 'stderr', severity: SEVERITY_WARN, why: 'Cloud captures logs from stderr; anything else goes to the ephemeral disk');

    // ---- Platform / tenancy ------------------------------------------------
    // config/platform.php falls back to `plateful.test` — the same silent-fallback
    // class as the media disk: the app boots, but every storefront subdomain and
    // the admin host resolve against the wrong domain.
    section('Platform / tenancy');
    primaryDomain($vars, $r);
    show('PLATFORM_ADMIN_SUBDOMAIN', $vars, $r, severity: SEVERITY_NONE);
    present('PLATFORM_BOOKING_URL', $vars, $r, severity: SEVERITY_WARN, why: '/book falls back to the self-serve signup flow');
    sessionDomain($vars, $r);

    // ---- Stripe (live vs test) --------------------------------------------
    section('Stripe');
    stripeKey('STRIPE_KEY', $vars, $r);
    stripeKey('STRIPE_SECRET', $vars, $r);
    present('STRIPE_WEBHOOK_SECRET', $vars, $r, prefix: 'whsec_', why: 'orders only materialize reliably via the Connect webhook');
    show('STRIPE_CONNECT_COUNTRY', $vars, $r, severity: SEVERITY_NONE);

    // ---- POS (Square / Clover) ----------------------------------------------
    // Both default to 'sandbox' in config/services.php, and host selection keys
    // entirely off these vars — if production doesn't set them explicitly, every
    // OAuth connect and ticket push silently goes to the sandbox hosts and real
    // registers never see an order. Same silent-fallback class as the media disk.
    section('POS (Square / Clover)');
    show('SQUARE_ENVIRONMENT', $vars, $r, expect: 'production', why: 'sandbox hosts: real Square registers never see an order');
    present('SQUARE_APPLICATION_ID', $vars, $r);
    present('SQUARE_APPLICATION_SECRET', $vars, $r);
    show('SQUARE_REDIRECT_URI', $vars, $r, why: 'the OAuth connect flow cannot complete without it');
    show('CLOVER_ENVIRONMENT', $vars, $r, expect: 'production', why: 'sandbox hosts: real Clover registers never see an order (waits on Clover production approval — todo.md §0)');
    present('CLOVER_APP_ID', $vars, $r, why: 'production app credentials (todo.md §0)');
    present('CLOVER_APP_SECRET', $vars, $r, why: 'production app credentials (todo.md §0)');
    show('CLOVER_REDIRECT_URI', $vars, $r, why: 'the OAuth connect flow cannot complete without it');

    // ---- Onboarding & address lookup ----------------------------------------
    section('Onboarding & address lookup');
    // AI menu import (the "free setup" pitch) dies silently without this.
    present('CLAUDE_API_KEY', $vars, $r, why: 'menu import (the "free setup" pitch) fails silently');
    // Places autocomplete + delivery quotes need the server-side Maps key.
    present('GOOGLE_MAPS_API_KEY', $vars, $r, why: 'address autocomplete and delivery quotes fail');
    present('GOOGLE_GEOCODING_API_KEY', $vars, $r, severity: SEVERITY_NONE);
    line('  (GOOGLE_GEOCODING_API_KEY falls back to GOOGLE_MAPS_API_KEY when unset)');

    // ---- Google login (optional but wired) ----------------------------------
    section('Google login');
    present('GOOGLE_CLIENT_ID', $vars, $r, severity: SEVERITY_WARN, why: '"Sign in with Google" is wired but will error');
    present('GOOGLE_CLIENT_SECRET', $vars, $r, severity: SEVERITY_WARN, why: '"Sign in with Google" is wired but will error');
    show('GOOGLE_REDIRECT_URI', $vars, $r, severity: SEVERITY_WARN, why: 'must be the root-host callback registered on the Google OAuth client');

    // ---- Delivery: Uber Direct (interim LIVE courier network) ---------------
    // Umbrella / central-billing: one platform credential set for every
    // restaurant. Live since 2026-08-12 and the launch courier network until
    // DoorDash production access lands, so these block.
    section('Delivery — Uber Direct (interim live courier network)');
    present('UBER_DIRECT_CLIENT_ID', $vars, $r, why: 'the live courier network cannot authenticate');
    present('UBER_DIRECT_CLIENT_SECRET', $vars, $r, why: 'the live courier network cannot authenticate');
    present('UBER_DIRECT_CUSTOMER_ID', $vars, $r, why: 'restaurants cannot be provisioned as sub-organizations');
    present('UBER_DIRECT_WEBHOOK_SECRET', $vars, $r, why: 'status webhooks are rejected; every delivery waits out the courier deadline');

    // ---- Delivery: DoorDash Drive (launch provider, pending prod access) ----
    // Platform-level creds (not per-restaurant). Unset in production means the
    // provider silently fails to mint JWTs / verify webhooks — but launch does
    // not hang on it while Uber Direct is live, so these warn rather than block.
    section('Delivery — DoorDash Drive (waits on production access)');
    present('DOORDASH_DEVELOPER_ID', $vars, $r, severity: SEVERITY_WARN, why: 'DoorDash dispatch fails until production access is granted (todo.md §3 Session 0/6)');
    present('DOORDASH_KEY_ID', $vars, $r, severity: SEVERITY_WARN, why: 'DoorDash dispatch fails until production access is granted');
    present('DOORDASH_SIGNING_SECRET', $vars, $r, severity: SEVERITY_WARN, why: 'DoorDash dispatch fails until production access is granted');
    // Webhook HMAC secret. Without it DoorDashWebhookController fails closed and
    // every delivery waits out the courier deadline instead of settling on signal.
    present('DOORDASH_WEBHOOK_SECRET', $vars, $r, severity: SEVERITY_WARN, why: 'DoorDash status webhooks fail closed');

    // ---- Queue ---------------------------------------------------------------
    // POS pushes, delivery dispatch, and the auth/capture deadline are all queued
    // jobs. QUEUE_CONNECTION=database with no worker means orders never push,
    // deliveries never dispatch, and card holds never release. The worker itself
    // can't be seen from env vars; confirm it in the Cloud dashboard (DEPLOY.md).
    // The hourly `orders:alert-stale-authorizations` schedule reports stranded
    // holds to Sentry, which is the in-code backstop for a dead worker.
    section('Queue');
    show('QUEUE_CONNECTION', $vars, $r, severity: SEVERITY_WARN, why: 'defaults to database; a worker must be provisioned');
    line('  NOTE: a queue worker must be provisioned — env vars cannot prove one is running.');

    // ---- Mail (Resend) -------------------------------------------------------
    section('Mail');
    show('MAIL_MAILER', $vars, $r, expect: 'resend', why: 'order confirmations, receipts and password resets do not send');
    show('MAIL_FROM_ADDRESS', $vars, $r, why: 'framework auth mail has no sender');
    present('RESEND_API_KEY', $vars, $r, prefix: 're_', why: 'nothing sends');
    show('MAIL_FROM_ORDERS_ADDRESS', $vars, $r, severity: SEVERITY_WARN, why: 'order mail falls back to the global sender');
    show('MAIL_FROM_SERVICE_ADDRESS', $vars, $r, severity: SEVERITY_WARN, why: 'service mail falls back to the global sender');
    show('MAIL_FROM_SUPPORT_ADDRESS', $vars, $r, severity: SEVERITY_WARN, why: 'support mail falls back to the global sender');
    show('MARKETING_MAIL_DOMAIN', $vars, $r, severity: SEVERITY_WARN, why: 'campaign sends fall back to the transactional domain');
    present('RESEND_WEBHOOK_SECRET', $vars, $r, severity: SEVERITY_WARN, why: 'bounces / complaints are not recorded, so suppressed addresses keep getting mail');

    // ---- Error monitoring ----------------------------------------------------
    // sentry/sentry-laravel is wired in bootstrap/app.php and is a no-op until a
    // DSN exists. Verify after setting it: `php artisan sentry:test` in Cloud.
    section('Error monitoring');
    present('SENTRY_LARAVEL_DSN', $vars, $r, why: 'production exceptions are invisible (only in the stderr log)');
    show('SENTRY_TRACES_SAMPLE_RATE', $vars, $r, severity: SEVERITY_NONE);
    line('  (SENTRY_TRACES_SAMPLE_RATE defaults to 0.1 in config/sentry.php; only the DSN is required)');

    // ---- Storage -------------------------------------------------------------
    section('Storage');
    show('FILESYSTEM_DISK', $vars, $r, expect: 's3', why: 'uploads land on the ephemeral container disk');
    mediaDisk($vars, $r);
    present('AWS_ACCESS_KEY_ID', $vars, $r);
    present('AWS_BUCKET', $vars, $r);

    // ---- Recent application errors --------------------------------------------
    section('Recent errors (application logs, last 24h)');
    if ($logs === null) {
        line('  (could not fetch logs)');
    } else {
        $errors = array_values(array_filter($logs, fn ($l) => ($l['level'] ?? '') === 'error' || ($l['type'] ?? '') === 'exception'));
        line('  '.count($errors).' error/exception entries in the last 24h (of '.count($logs).' log lines fetched)');
        foreach (array_slice($errors, 0, 5) as $l) {
            $msg = trim((string) ($l['message'] ?? ''));
            if (strlen($msg) > 140) {
                $msg = substr($msg, 0, 140).'…';
            }
            line('    ['.($l['logged_at'] ?? '?').'] '.$msg);
        }
        if ($errors !== []) {
            $r->add(SEVERITY_WARN, 'recent errors', count($errors).' error/exception log entries in the last 24h — read them before launch');
        }
    }

    return $r;
}

function print_summary(CloudCheckReport $r): void
{
    section('Launch blockers ('.count($r->blockers).')');
    if ($r->blockers === []) {
        line('  none — every §0 code/config check passes');
    }
    foreach ($r->blockers as $b) {
        line('  - '.$b['key'].': '.$b['why']);
    }

    section('Warnings ('.count($r->warnings).')');
    if ($r->warnings === []) {
        line('  none');
    }
    foreach ($r->warnings as $w) {
        line('  - '.$w['key'].': '.$w['why']);
    }

    line('');
    line($r->ready() ? 'READY: no launch blockers in the Cloud environment.' : 'NOT READY: '.count($r->blockers).' launch blocker(s) above.');
    line('Done. This report contains no secret values and is safe to share.');
}

// ---------------------------------------------------------------------------
// token lookup
// ---------------------------------------------------------------------------

function toolbelt_env_path(): string
{
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');

    return $home.'/.config/claude-tools/env';
}

/**
 * Find LARAVEL_CLOUD_TOKEN without requiring it to live in the project.
 * Order: the project's .env, the process environment, the toolbelt key file
 * (~/.config/claude-tools/env — the same KEY=value format).
 */
function read_cloud_token(string $envPath, ?string $fromEnvironment, string $toolbeltPath): ?string
{
    return read_env_file_key($envPath, 'LARAVEL_CLOUD_TOKEN')
        ?? ($fromEnvironment !== null && $fromEnvironment !== '' ? $fromEnvironment : null)
        ?? read_env_file_key($toolbeltPath, 'LARAVEL_CLOUD_TOKEN');
}

function read_env_file_key(string $path, string $key): ?string
{
    if (! is_file($path)) {
        return null;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $l) {
        if (preg_match('/^\s*(?:export\s+)?'.preg_quote($key, '/').'\s*=\s*(.*)$/', $l, $m)) {
            $value = trim($m[1], " \t\"'");

            return $value !== '' ? $value : null;
        }
    }

    return null;
}

// ---------------------------------------------------------------------------
// Cloud API
// ---------------------------------------------------------------------------

function api(string $url, string $token): array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'GET',
        'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
        'timeout' => 30,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        fwrite(STDERR, "Request failed: {$url}\n");

        return [];
    }
    $status = 0;
    foreach (($http_response_header ?? []) as $h) {
        if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int) $m[1];
        }
    }
    if ($status >= 400) {
        fwrite(STDERR, "HTTP {$status} for {$url}: ".substr($raw, 0, 300)."\n");

        return [];
    }

    return json_decode($raw, true) ?: [];
}

/** Pick a resource by explicit name, else by preferred names, else the only one. */
function pick(array $items, ?string $want, array $preferred): ?array
{
    $nameOf = fn ($i) => strtolower((string) ($i['attributes']['slug'] ?? $i['attributes']['name'] ?? ''));
    $altOf = fn ($i) => strtolower((string) ($i['attributes']['name'] ?? ''));

    if ($want !== null) {
        foreach ($items as $i) {
            if ($nameOf($i) === strtolower($want) || $altOf($i) === strtolower($want)) {
                return $i;
            }
        }
    }
    foreach ($preferred as $p) {
        foreach ($items as $i) {
            if ($nameOf($i) === $p || $altOf($i) === $p) {
                return $i;
            }
        }
    }

    return count($items) === 1 ? $items[0] : null;
}

// ---------------------------------------------------------------------------
// check helpers — each prints one line and records a finding on failure
// ---------------------------------------------------------------------------

function section(string $t): void
{
    line('');
    line($t);
    line(str_repeat('-', strlen($t)));
}

/**
 * A key that is set by Cloud itself (see CLOUD_INJECTED) may legitimately be
 * absent from the API's list, so its absence downgrades to a warning.
 */
function missingSeverity(string $key, string $severity): string
{
    if ($severity === SEVERITY_BLOCKER && in_array($key, CLOUD_INJECTED, strict: true)) {
        return SEVERITY_WARN;
    }

    return $severity;
}

function missingNote(string $key): string
{
    return in_array($key, CLOUD_INJECTED, strict: true)
        ? ' (Cloud may inject this without listing it — confirm in the dashboard)'
        : '';
}

/**
 * Show a non-secret value, optionally against an expected one. Missing or
 * mismatched values are recorded at $severity (missing Cloud-injected keys
 * downgrade to a warning).
 */
function show(string $key, array $vars, CloudCheckReport $r, ?string $expect = null, string $severity = SEVERITY_BLOCKER, string $why = ''): void
{
    if (! array_key_exists($key, $vars) || $vars[$key] === '') {
        line(sprintf('  %-38s MISSING%s', $key, missingNote($key)));
        $r->add(missingSeverity($key, $severity), $key, 'missing'.($why !== '' ? ' — '.$why : ''));

        return;
    }
    $val = $vars[$key];
    $ok = $expect === null || strtolower((string) $val) === strtolower($expect);
    $flag = $expect !== null ? ($ok ? ' [ok]' : ' [expected '.$expect.']') : '';
    line(sprintf('  %-38s %s%s', $key, $val, $flag));
    if (! $ok) {
        $r->add($severity, $key, 'is "'.$val.'", expected "'.$expect.'"'.($why !== '' ? ' — '.$why : ''));
    }
}

/** Report only presence/absence (and optional expected prefix) — never the value. */
function present(string $key, array $vars, CloudCheckReport $r, ?string $prefix = null, string $severity = SEVERITY_BLOCKER, string $why = ''): void
{
    $has = array_key_exists($key, $vars) && $vars[$key] !== '';
    $note = '';
    if ($has && $prefix !== null) {
        $prefixOk = str_starts_with($vars[$key], $prefix);
        $note = $prefixOk ? ' ('.$prefix.'…)' : ' (unexpected prefix)';
        if (! $prefixOk) {
            $r->add($severity, $key, 'does not start with "'.$prefix.'" — probably the wrong kind of key');
        }
    }
    line(sprintf('  %-38s %s%s%s', $key, $has ? 'present' : 'ABSENT', $note, $has ? '' : missingNote($key)));
    if (! $has) {
        $r->add(missingSeverity($key, $severity), $key, 'absent'.($why !== '' ? ' — '.$why : ''));
    }
}

/** Record a free-form finding (printed as a note line). */
function note(string $message, CloudCheckReport $r, string $severity, string $key): void
{
    line('  NOTE: '.$message);
    $r->add($severity, $key, $message);
}

/** Report Stripe key as LIVE / TEST by prefix, never the value. */
function stripeKey(string $key, array $vars, CloudCheckReport $r): void
{
    if (! array_key_exists($key, $vars) || $vars[$key] === '') {
        line(sprintf('  %-38s MISSING', $key));
        $r->add(SEVERITY_BLOCKER, $key, 'missing — no payments at all');

        return;
    }
    $v = $vars[$key];
    $mode = str_contains($v, '_live_') ? 'LIVE' : (str_contains($v, '_test_') ? 'TEST' : 'unknown');
    $prefix = explode('_', $v)[0] ?? '';
    line(sprintf('  %-38s %s (%s_…)%s', $key, $mode, $prefix, $mode === 'LIVE' ? ' [ok]' : ' [still test mode]'));
    if ($mode !== 'LIVE') {
        $r->add(SEVERITY_BLOCKER, $key, $mode === 'TEST' ? 'is a TEST-mode key — no real money moves' : 'is not recognisably a live key');
    }
}

/**
 * config/platform.php falls back to `plateful.test`. A production environment
 * that leaves it unset (or on a .test domain) resolves every tenant host wrong.
 */
function primaryDomain(array $vars, CloudCheckReport $r): void
{
    $domain = (string) ($vars['PLATFORM_PRIMARY_DOMAIN'] ?? '');
    if ($domain === '') {
        line(sprintf('  %-38s MISSING', 'PLATFORM_PRIMARY_DOMAIN'));
        $r->add(SEVERITY_BLOCKER, 'PLATFORM_PRIMARY_DOMAIN', 'missing — config falls back to plateful.test and no tenant host resolves');

        return;
    }
    $ok = ! str_ends_with($domain, '.test') && ! str_ends_with($domain, '.localhost');
    line(sprintf('  %-38s %s%s', 'PLATFORM_PRIMARY_DOMAIN', $domain, $ok ? ' [ok]' : ' [local-only domain]'));
    if (! $ok) {
        $r->add(SEVERITY_BLOCKER, 'PLATFORM_PRIMARY_DOMAIN', 'is "'.$domain.'", a local-only domain');
    }
}

/**
 * Multi-tenant cookies: SESSION_DOMAIN must stay unset so each tenant host
 * (admin.X, marcos.X, …) keeps its own cookie (DEPLOY.md troubleshooting).
 */
function sessionDomain(array $vars, CloudCheckReport $r): void
{
    $value = (string) ($vars['SESSION_DOMAIN'] ?? '');
    $ok = $value === '' || strtolower($value) === 'null';
    line(sprintf('  %-38s %s%s', 'SESSION_DOMAIN', $value === '' ? '(unset)' : $value, $ok ? ' [ok]' : ' [should be unset]'));
    if (! $ok) {
        $r->add(SEVERITY_WARN, 'SESSION_DOMAIN', 'is set to "'.$value.'" — tenant hosts share one cookie; leave it unset');
    }
}

/**
 * Report the disk restaurant media actually resolves to.
 *
 * Mirrors config/media.php: MEDIA_DISK if set, otherwise FILESYSTEM_DISK, otherwise
 * "local". Checking the env vars in isolation is not enough — media rides the
 * fallback, so FILESYSTEM_DISK=local with MEDIA_DISK unset silently parks every
 * logo and menu photo on the container's ephemeral disk.
 */
function mediaDisk(array $vars, CloudCheckReport $r): void
{
    $media = (string) ($vars['MEDIA_DISK'] ?? '');
    $default = (string) ($vars['FILESYSTEM_DISK'] ?? '');

    if ($media !== '') {
        $effective = $media;
        $source = 'MEDIA_DISK';
    } else {
        $effective = $default !== '' ? $default : 'local';
        $source = 'FILESYSTEM_DISK fallback';
    }

    $ok = strtolower($effective) === 's3';
    line(sprintf(
        '  %-38s %s (via %s)%s',
        'media disk (effective)',
        $effective,
        $source,
        $ok ? ' [ok]' : ' [expected s3 — uploads are NOT durable]'
    ));
    if (! $ok) {
        // If FILESYSTEM_DISK is simply not listed (Cloud injects it), the
        // "local" here is the API's blind spot, not necessarily the truth.
        $severity = ($media === '' && $default === '') ? SEVERITY_WARN : SEVERITY_BLOCKER;
        $r->add($severity, 'media disk', 'resolves to "'.$effective.'" via '.$source.' — uploads are not durable unless the dashboard shows the bucket as the default disk');
    }
}

function line(string $s): void
{
    echo $s, "\n";
}

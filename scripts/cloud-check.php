<?php

/**
 * Laravel Cloud production readiness checker (read-only).
 *
 * Reads LARAVEL_CLOUD_TOKEN from the project's .env, queries the Laravel Cloud
 * API for the Plateful production environment, and prints a readiness report.
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
 */

const BASE = 'https://cloud.laravel.com/api';

$root = dirname(__DIR__);
$token = read_env_token($root.'/.env');
if (! $token) {
    fwrite(STDERR, "ERROR: LARAVEL_CLOUD_TOKEN not found in .env\n");
    exit(1);
}

$wantApp = $argv[1] ?? null;   // optional app slug/name filter
$wantEnv = $argv[2] ?? null;   // optional environment name filter

line('Laravel Cloud readiness check');
line(str_repeat('=', 40));

// 1. Find the application.
$apps = api(BASE.'/applications', $token)['data'] ?? [];
if (! $apps) {
    fwrite(STDERR, "No applications returned. Is the token valid?\n");
    exit(1);
}
$app = pick($apps, $wantApp, ['plateful']);
if (! $app) {
    line("Could not auto-pick an app. Available:");
    foreach ($apps as $a) {
        line('  - '.($a['attributes']['slug'] ?? $a['id']).'  ('.($a['attributes']['name'] ?? '').')');
    }
    line("Re-run with:  php scripts/cloud-check.php <app-slug>");
    exit(0);
}
$appId = $app['id'];
line('App:         '.($app['attributes']['name'] ?? $appId));
line('Repo:        '.($app['attributes']['repository']['full_name'] ?? 'n/a'));

// 2. Find the environment.
$envs = api(BASE.'/applications/'.$appId.'/environments', $token)['data'] ?? [];
$env = pick($envs, $wantEnv, ['production', 'main']);
if (! $env) {
    line("Could not auto-pick an environment. Available:");
    foreach ($envs as $e) {
        line('  - '.($e['attributes']['name'] ?? $e['id']));
    }
    line("Re-run with:  php scripts/cloud-check.php <app-slug> <env-name>");
    exit(0);
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

// ---- App config ------------------------------------------------------------
section('App');
show('APP_ENV', $vars, expect: 'production');
show('APP_DEBUG', $vars, expect: 'false');
show('APP_URL', $vars);
show('LOG_CHANNEL', $vars);

// ---- Stripe (live vs test) -------------------------------------------------
section('Stripe');
stripeKey('STRIPE_KEY', $vars);
stripeKey('STRIPE_SECRET', $vars);
present('STRIPE_WEBHOOK_SECRET', $vars, 'whsec_');
show('STRIPE_CONNECT_COUNTRY', $vars);

// ---- Mail (Resend) ---------------------------------------------------------
section('Mail');
show('MAIL_MAILER', $vars, expect: 'resend');
show('MAIL_FROM_ADDRESS', $vars);
present('RESEND_API_KEY', $vars, 're_');

// ---- Error monitoring ------------------------------------------------------
section('Error monitoring');
present('SENTRY_LARAVEL_DSN', $vars);

// ---- Storage ---------------------------------------------------------------
section('Storage');
show('FILESYSTEM_DISK', $vars);
show('FILESYSTEM_RESTAURANT_ASSETS_DRIVER', $vars, expect: 's3');
present('AWS_ACCESS_KEY_ID', $vars);
present('AWS_BUCKET', $vars);

// 4. Recent application errors (last 24h).
section('Recent errors (application logs, last 24h)');
$from = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);
$to = gmdate('Y-m-d\TH:i:s\Z', time());
$logsUrl = BASE.'/environments/'.$envId.'/logs?type=application&from='.rawurlencode($from).'&to='.rawurlencode($to);
$logs = api($logsUrl, $token)['data'] ?? null;
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
}

line('');
line('Done. This report contains no secret values and is safe to share.');

// ---------------------------------------------------------------------------
// helpers
// ---------------------------------------------------------------------------

function read_env_token(string $path): ?string
{
    if (! is_file($path)) {
        return null;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $l) {
        if (preg_match('/^\s*LARAVEL_CLOUD_TOKEN\s*=\s*(.*)$/', $l, $m)) {
            return trim($m[1], " \t\"'");
        }
    }

    return null;
}

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

function section(string $t): void
{
    line('');
    line($t);
    line(str_repeat('-', strlen($t)));
}

function show(string $key, array $vars, ?string $expect = null): void
{
    if (! array_key_exists($key, $vars)) {
        line(sprintf('  %-38s MISSING', $key));

        return;
    }
    $val = $vars[$key];
    $flag = $expect !== null ? (strtolower((string) $val) === strtolower($expect) ? ' [ok]' : ' [expected '.$expect.']') : '';
    line(sprintf('  %-38s %s%s', $key, $val, $flag));
}

/** Report Stripe key as LIVE / TEST by prefix, never the value. */
function stripeKey(string $key, array $vars): void
{
    if (! array_key_exists($key, $vars) || $vars[$key] === '') {
        line(sprintf('  %-38s MISSING', $key));

        return;
    }
    $v = $vars[$key];
    $mode = str_contains($v, '_live_') ? 'LIVE' : (str_contains($v, '_test_') ? 'TEST' : 'unknown');
    $prefix = explode('_', $v)[0] ?? '';
    line(sprintf('  %-38s %s (%s_…)%s', $key, $mode, $prefix, $mode === 'LIVE' ? ' [ok]' : ' [still test mode]'));
}

/** Report only presence/absence (and optional expected prefix) — never the value. */
function present(string $key, array $vars, ?string $prefix = null): void
{
    $has = array_key_exists($key, $vars) && $vars[$key] !== '';
    $note = '';
    if ($has && $prefix !== null) {
        $note = str_starts_with($vars[$key], $prefix) ? ' ('.$prefix.'…)' : ' (unexpected prefix)';
    }
    line(sprintf('  %-38s %s%s', $key, $has ? 'present' : 'ABSENT', $note));
}

function line(string $s): void
{
    fwrite(STDOUT, $s."\n");
}

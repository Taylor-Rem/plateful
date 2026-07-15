<?php

use App\Providers\AppServiceProvider;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

it('forces HTTPS scheme when running in production', function () {
    app()->detectEnvironment(fn () => 'production');

    // Re-boot AppServiceProvider so its production-only logic runs.
    (new AppServiceProvider(app()))->boot();

    expect(URL::formatScheme(null))->toBe('https://');
})->skip(
    fn () => ! app()->environment('testing'),
    'Sanity-only test for the production-only URL::forceScheme call.',
);

it('resolves the Resend mail driver from config', function () {
    config()->set('mail.default', 'resend');
    config()->set('services.resend.key', 're_test_key');

    $mailer = Mail::mailer('resend');

    expect($mailer->getSymfonyTransport())->toBeInstanceOf(ResendTransport::class);
});

/**
 * Re-evaluate config/media.php against a given environment.
 *
 * @return string the disk restaurant media would resolve to
 */
function resolveMediaDisk(?string $mediaDisk, ?string $filesystemDisk): string
{
    $restore = [
        'MEDIA_DISK' => $_ENV['MEDIA_DISK'] ?? null,
        'FILESYSTEM_DISK' => $_ENV['FILESYSTEM_DISK'] ?? null,
    ];

    foreach (['MEDIA_DISK' => $mediaDisk, 'FILESYSTEM_DISK' => $filesystemDisk] as $key => $value) {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
        } else {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
    }

    try {
        return (require base_path('config/media.php'))['disk'];
    } finally {
        foreach ($restore as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
    }
}

/**
 * Restaurant media (logos, menu photos) rides FILESYSTEM_DISK unless MEDIA_DISK
 * overrides it. DEPLOY.md once prescribed FILESYSTEM_DISK=local with MEDIA_DISK
 * unset, which silently parked every upload on the container's ephemeral disk —
 * these pin the fallback chain the runbook is derived from.
 */
it('resolves the media disk from the environment', function (?string $media, ?string $filesystem, string $expected) {
    expect(resolveMediaDisk($media, $filesystem))->toBe($expected);
})->with([
    'Cloud: MEDIA_DISK unset follows the default disk onto the bucket' => [null, 's3', 's3'],
    'MEDIA_DISK overrides a durable default' => ['public', 's3', 'public'],
    'MEDIA_DISK rescues a local default' => ['s3', 'local', 's3'],
    'the misconfiguration DEPLOY.md used to prescribe' => [null, 'local', 'local'],
    'no configuration at all falls back to local' => [null, null, 'local'],
]);

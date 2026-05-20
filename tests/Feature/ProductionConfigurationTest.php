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

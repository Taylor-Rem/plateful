<?php

use Illuminate\Contracts\Debug\ExceptionHandler;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\State\HubInterface;

/**
 * Verifies that unhandled exceptions are routed to Sentry via the
 * `Integration::handles(...)` wiring in bootstrap/app.php.
 *
 * A `before_send` hook captures the event in-memory and returns `null`, which
 * discards it so no real event is transmitted during the test run.
 */
it('reports unhandled exceptions to Sentry', function () {
    $captured = [];

    config([
        // A syntactically valid dummy DSN so the SDK builds an active client.
        'sentry.dsn' => 'https://publickey@sentry.example.com/1',
        'sentry.before_send' => function (Event $event) use (&$captured) {
            $captured[] = $event;

            // Returning null discards the event so nothing is sent anywhere.
            return null;
        },
    ]);

    // Discard any hub/client resolved during boot so it is rebuilt from the
    // config set above, then resolve it as the current hub.
    app()->forgetInstance(ClientBuilder::class);
    app()->forgetInstance(HubInterface::class);
    app(HubInterface::class);

    // Route the exception through the application's exception handler — the same
    // path production uses — which triggers the Sentry reportable callback.
    app(ExceptionHandler::class)->report(new RuntimeException('boom'));

    expect($captured)->toHaveCount(1);
    expect($captured[0]->getExceptions()[0]->getValue())->toBe('boom');
});

it('does not configure a DSN when none is set (local/testing default)', function () {
    config(['sentry.dsn' => null]);

    $client = app(HubInterface::class)->getClient();

    expect($client?->getOptions()->getDsn())->toBeNull();
});

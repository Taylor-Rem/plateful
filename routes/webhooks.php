<?php

use App\Http\Controllers\DoorDashWebhookController;
use App\Http\Controllers\ResendWebhookController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\UberDirectWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Machine-to-machine webhook endpoints on the admin host. These URLs are
 * registered with external providers (Stripe, Uber, DoorDash) and must never
 * change — RouteArchitectureTest pins them. All are public (no auth),
 * CSRF-exempt via bootstrap/app.php, and signature-verified in their
 * controllers.
 */
Route::domain('admin.'.config('platform.primary_domain'))->group(function () {
    // Stripe Connect webhooks.
    Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

    // Uber Direct delivery-status webhooks. One URL for every tenant; the
    // webhook is configured once on Plateful's root Direct account, so a single
    // platform-level signing key verifies the signature and the payload
    // resolves to the restaurant via its provisioned sub-org / delivery ids.
    Route::post('/webhooks/uber', UberDirectWebhookController::class)->name('webhooks.uber');

    // DoorDash Drive delivery-status webhooks. One URL for every restaurant;
    // DoorDash is centrally billed, so a single platform-level secret verifies
    // the signature and the delivery is resolved by external_delivery_id.
    Route::post('/webhooks/doordash', DoorDashWebhookController::class)->name('webhooks.doordash');

    // Resend email-event webhooks (campaign delivered/bounced/complained).
    // One endpoint for the whole Resend account; events are matched to
    // campaign recipients by message id, so transactional mail is ignored.
    Route::post('/webhooks/resend', ResendWebhookController::class)->name('webhooks.resend');
});

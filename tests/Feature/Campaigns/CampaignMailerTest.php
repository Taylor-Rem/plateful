<?php

use App\Enums\CampaignRecipientStatus;
use App\Models\CampaignRecipient;
use App\Services\Campaigns\CampaignMailer;
use App\Services\MarketingConsentService;

require_once __DIR__.'/CampaignTestHelpers.php';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);
    config(['services.resend.key' => null]);
});

function mailerRecipient(): array
{
    $r = adminOrderRestaurant('marcos');
    $user = optedInCustomer($r, 'Alice Apple', 'alice@example.test');
    $c = campaign($r);

    $recipient = CampaignRecipient::create([
        'campaign_id' => $c->id,
        'user_id' => $user->id,
        'email' => $user->email,
        'status' => CampaignRecipientStatus::Queued,
    ]);

    return [$r, $user, $c, $recipient->load('user')];
}

test('message sends from the shared marketing domain with reply-to the restaurant inbox', function () {
    [$r, $user, $c, $recipient] = mailerRecipient();

    $message = app(CampaignMailer::class)->buildMessage($c, $recipient);

    expect($message['from'])->toBe('R-marcos <marcos@platefuloffers.fyi>')
        ->and($message['reply_to'])->toBe('hello@marcos.test')
        ->and($message['to'])->toBe(['alice@example.test'])
        ->and($message['subject'])->toBe('Slow Tuesday: half-price pies');
});

test('message carries RFC 8058 one-click unsubscribe headers with the signed Phase-1 URL', function () {
    [$r, $user, $c, $recipient] = mailerRecipient();

    $url = app(MarketingConsentService::class)->unsubscribeUrl($user, $r);
    $message = app(CampaignMailer::class)->buildMessage($c, $recipient);

    expect($url)->toContain('marketing/unsubscribe')->toContain('signature=')
        ->and($message['headers']['List-Unsubscribe'])->toBe('<'.$url.'>')
        ->and($message['headers']['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click');
});

test('html renders the structured fields and the platform compliance footer', function () {
    [$r, $user, $c, $recipient] = mailerRecipient();

    $url = app(MarketingConsentService::class)->unsubscribeUrl($user, $r);
    $html = app(CampaignMailer::class)->buildMessage($c, $recipient)['html'];

    expect($html)
        ->toContain('Half-price pies this Tuesday')
        ->toContain('Come hungry.')
        ->toContain('50% off all pies')
        // Footer: physical address, platform attribution, per-recipient unsubscribe.
        ->toContain('R-marcos')
        ->toContain('1 Main')
        ->toContain('Sent via Plateful')
        ->toContain(e($url));
});

test('default CTA is an Order now button to the storefront', function () {
    [$r, $user, $c, $recipient] = mailerRecipient();

    $html = app(CampaignMailer::class)->buildMessage($c, $recipient)['html'];

    expect($html)->toContain('Order now')
        ->toContain('https://marcos.plateful.test');
});

test('custom CTA label and URL override the default', function () {
    [$r, $user, $c, $recipient] = mailerRecipient();
    $c->forceFill(['cta_label' => 'Book a table', 'cta_url' => 'https://marcos.example/book'])->save();

    $html = app(CampaignMailer::class)->buildMessage($c->fresh(), $recipient)['html'];

    expect($html)->toContain('Book a table')
        ->toContain('https://marcos.example/book')
        ->not->toContain('>Order now<');
});

test('keyless mailer logs instead of sending and returns fake message ids', function () {
    [$r, $user, $c, $recipient] = mailerRecipient();

    $mailer = app(CampaignMailer::class);

    expect($mailer->isConfigured())->toBeFalse();

    $ids = $mailer->send($c, CampaignRecipient::query()->with('user')->get());

    expect($ids)->toHaveKey($recipient->id)
        ->and($ids[$recipient->id])->toStartWith('log-');
});

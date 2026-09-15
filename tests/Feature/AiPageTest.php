<?php

it('renders the AI page on the root domain', function () {
    $this->get('http://plateful.test/ai')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Ai'));
});

/*
| Cold prospects land here from the homepage and the footer — it must never
| sit behind auth.
*/
it('serves the AI page to guests', function () {
    $this->assertGuest();

    $this->get(route('ai'))->assertOk();
});

it('passes the published credit prices and the MCP endpoint as props, so the copy cannot drift', function () {
    $this->get(route('ai'))
        ->assertInertia(fn ($page) => $page
            ->component('Ai')
            ->where('mcpUrl', 'http://plateful.test/mcp/platform')
            ->where('messageFloorCents', 50)
            ->where('generatedPhotoCents', 100)
            ->where('creditPacksCents', [2500, 10000])
        );
});

it('offers no call booking by default, so the CTA falls back to email', function () {
    $this->get(route('ai'))
        ->assertInertia(fn ($page) => $page->where('canBookCall', false));
});

it('offers call booking when a booking url is configured', function () {
    config()->set('platform.booking_url', 'https://cal.com/plateful/intro');

    $this->get(route('ai'))
        ->assertInertia(fn ($page) => $page->where('canBookCall', true));
});

it('advertises the MCP endpoint that actually exists', function () {
    expect(route('mcp.platform'))->toBe('http://plateful.test/mcp/platform');
});

it('is listed in the sitemap', function () {
    $this->get('http://plateful.test/sitemap.xml')
        ->assertOk()
        ->assertSee('http://plateful.test/ai');
});

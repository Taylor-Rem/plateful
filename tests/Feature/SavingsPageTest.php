<?php

it('renders the savings calculator on the root domain', function () {
    $this->get('http://plateful.test/savings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Savings'));
});

/*
| Every marketing channel points cold prospects here — it must never sit
| behind auth.
*/
it('serves the savings calculator to guests', function () {
    $this->assertGuest();

    $this->get(route('savings'))->assertOk();
});

it('passes the live pricing config as props, so page math cannot drift from real rates', function () {
    config()->set('platform.default_application_fee_percent', 4.00);
    config()->set('platform.stripe_variable_rate', 0.029);
    config()->set('platform.commission_monthly_cap_cents', 39900);

    $this->get(route('savings'))
        ->assertInertia(fn ($page) => $page
            ->component('Savings')
            ->where('feePercent', 4)
            ->where('feeCapCents', 39900)
            ->where('stripeVariableRate', 0.029)
            ->where('stripeFixedFeeCents', 30)
        );
});

it('passes no booking url by default, so the page falls back to the signup CTA', function () {
    $this->get(route('savings'))
        ->assertInertia(fn ($page) => $page->where('bookingUrl', null));
});

it('passes the booking url when one is configured', function () {
    config()->set('platform.booking_url', 'https://cal.com/plateful/intro');

    $this->get(route('savings'))
        ->assertInertia(fn ($page) => $page
            ->where('bookingUrl', 'https://cal.com/plateful/intro')
        );
});

it('is listed in the sitemap', function () {
    $this->get('http://plateful.test/sitemap.xml')
        ->assertOk()
        ->assertSee('http://plateful.test/savings');
});

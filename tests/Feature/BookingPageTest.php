<?php

it('redirects to the for-restaurants page when no booking link is configured', function () {
    config()->set('platform.booking_url', null);

    $this->get('http://plateful.test/book')
        ->assertRedirect(route('owner-signup.landing'));
});

it('renders the embedded booking page for a cal.com link', function () {
    config()->set('platform.booking_url', 'https://cal.com/plateful-founder/15min');
    config()->set('platform.booking_url_long', 'https://cal.com/plateful-founder/30min');

    $this->get(route('booking'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Book')
            ->where('calLink', 'plateful-founder/15min')
            ->where('bookingUrl', 'https://cal.com/plateful-founder/15min')
            ->where('longBookingUrl', 'https://cal.com/plateful-founder/30min')
        );
});

it('passes no long booking url when only the primary link is configured', function () {
    config()->set('platform.booking_url', 'https://cal.com/plateful-founder/15min');

    $this->get(route('booking'))
        ->assertInertia(fn ($page) => $page->where('longBookingUrl', null));
});

it('redirects away to a non-cal.com scheduling link', function () {
    config()->set('platform.booking_url', 'https://calendly.com/plateful/intro');

    $this->get(route('booking'))
        ->assertRedirect('https://calendly.com/plateful/intro');
});

it('tells the for-restaurants landing page whether booking is available', function () {
    config()->set('platform.booking_url', 'https://cal.com/plateful-founder/15min');

    $this->get(route('owner-signup.landing'))
        ->assertInertia(fn ($page) => $page->where('canBookCall', true));

    config()->set('platform.booking_url', null);

    $this->get(route('owner-signup.landing'))
        ->assertInertia(fn ($page) => $page->where('canBookCall', false));
});

/*
| Cold prospects land here from printed one-pagers and bios — it must never
| sit behind auth.
*/
it('serves the booking page to guests', function () {
    config()->set('platform.booking_url', 'https://cal.com/plateful-founder/15min');

    $this->assertGuest();

    $this->get(route('booking'))->assertOk();
});

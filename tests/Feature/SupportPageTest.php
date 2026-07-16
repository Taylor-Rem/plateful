<?php

it('renders the support page on the root domain', function () {
    $this->get('http://plateful.test/support')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Support'));
});

/*
| The Clover app review team opens this URL without a Plateful account, so it
| must never sit behind auth.
*/
it('serves the support page to guests', function () {
    $this->assertGuest();

    $this->get(route('support'))->assertOk();
});

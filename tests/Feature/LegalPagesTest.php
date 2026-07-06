<?php

it('renders the terms of service page on the root domain', function () {
    $this->get('http://plateful.test/terms')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Legal/Terms'));
});

it('renders the privacy policy page on the root domain', function () {
    $this->get('http://plateful.test/privacy')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Legal/Privacy'));
});

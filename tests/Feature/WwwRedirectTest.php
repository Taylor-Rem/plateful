<?php

/*
| "www" is reserved and never a tenant, so it used to fall through to the
| tenant resolver and 404 — Search Console flagged both www roots. It must
| permanently redirect to the apex host instead.
*/
it('permanently redirects the www host to the apex domain', function () {
    $this->get('http://www.plateful.test/')
        ->assertStatus(301)
        ->assertRedirect('http://plateful.test/');
});

it('preserves the path and query string when redirecting www', function () {
    $this->get('http://www.plateful.test/for-restaurants?utm_source=flyer')
        ->assertStatus(301)
        ->assertRedirect('http://plateful.test/for-restaurants?utm_source=flyer');
});

it('does not capture tenant subdomains with the www redirect', function () {
    $this->get('http://marcos.plateful.test/')->assertNotFound();
});

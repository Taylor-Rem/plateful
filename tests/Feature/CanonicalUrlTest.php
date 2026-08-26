<?php

/*
| Every Inertia page declares a self-referencing canonical URL from the root
| template. Without one, Google picked its own canonical for /for-restaurants
| (it chose /book, which 301s there when no booking link is configured) and
| dropped the page from the index.
*/
it('declares a self-referencing canonical URL on the marketing pages', function () {
    config()->set('platform.booking_url', 'https://cal.com/plateful-founder/15min');

    foreach (['/for-restaurants', '/support', '/savings', '/book', '/terms', '/privacy'] as $path) {
        $this->get('http://plateful.test'.$path)
            ->assertOk()
            ->assertSee('<link rel="canonical" href="http://plateful.test'.$path.'">', false);
    }
});

it('never includes the query string in the canonical URL', function () {
    $this->get('http://plateful.test/for-restaurants?utm_source=google')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="http://plateful.test/for-restaurants">', false);
});

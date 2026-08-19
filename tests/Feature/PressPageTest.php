<?php

/*
| The press page is what reporters (and their link-checkers) hit before
| deciding a pitch is real — it must be public, server-rendered, and carry
| its own SEO head. It links two stories by slug, so those slugs existing
| in the content directory is part of the contract.
*/

it('serves the press page to guests with full SEO head tags', function () {
    $this->assertGuest();

    $this->get('http://plateful.test/press')
        ->assertOk()
        ->assertSee('<title>Press &amp; Media | Plateful</title>', false)
        ->assertSee('<link rel="canonical" href="http://plateful.test/press">', false)
        ->assertSee('founder@plateful.fyi')
        ->assertSee('Taylor Remund')
        ->assertSee('486 of 852')
        ->assertSee('/plateful-logo.svg', false);
});

it('links only stories that actually exist in the content directory', function () {
    $response = $this->get('http://plateful.test/press')->assertOk();

    preg_match_all('/href="[^"]*\/stories\/([a-z0-9-]+)"/', $response->getContent(), $matches);

    // The layout's RSS <link> also matches; 'feed' is a route, not a story.
    $slugs = array_diff(array_unique($matches[1]), ['feed']);

    expect($slugs)->not->toBeEmpty();

    foreach ($slugs as $slug) {
        expect(file_exists(base_path("content/stories/{$slug}.md")))
            ->toBeTrue("Press page links /stories/{$slug} but content/stories/{$slug}.md does not exist");
    }
});

it('is listed in the sitemap', function () {
    $this->get('http://plateful.test/sitemap.xml')
        ->assertOk()
        ->assertSee('<loc>http://plateful.test/press</loc>', false);
});

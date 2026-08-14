<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->storiesPath = storage_path('framework/testing/sitemap-stories-'.uniqid());
    File::makeDirectory($this->storiesPath, recursive: true);
    config()->set('stories.path', $this->storiesPath);
});

afterEach(function () {
    File::deleteDirectory($this->storiesPath);
});

function writeSitemapStory(string $slug, bool $published, string $date = '2026-01-15'): void
{
    $yaml = Yaml::dump([
        'title' => str($slug)->headline()->value(),
        'date' => $date,
        'excerpt' => 'Excerpt.',
        'published' => $published,
    ]);

    File::put(test()->storiesPath."/{$slug}.md", "---\n{$yaml}---\n\nBody.\n");
}

it('lists the marketing pages and published stories, excluding drafts', function () {
    writeSitemapStory('live-story', published: true);
    writeSitemapStory('draft-story', published: false);
    writeSitemapStory('future-story', published: true, date: '2100-01-01');

    $response = $this->get('http://plateful.test/sitemap.xml')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $sitemap = simplexml_load_string($response->getContent());
    expect($sitemap)->not->toBeFalse();

    // xpath by local-name: the urlset default namespace hides <loc> from
    // SimpleXML's property access.
    $locations = collect($sitemap->xpath('//*[local-name()="loc"]'))->map(strval(...));

    expect($locations->all())
        ->toContain('http://plateful.test')
        ->toContain('http://plateful.test/for-restaurants')
        ->toContain('http://plateful.test/support')
        ->toContain('http://plateful.test/stories')
        ->toContain('http://plateful.test/stories/live-story')
        ->not->toContain('http://plateful.test/stories/draft-story')
        ->not->toContain('http://plateful.test/stories/future-story');
});

it('includes a lastmod date for stories', function () {
    writeSitemapStory('dated-story', published: true, date: '2026-04-02');

    $this->get('http://plateful.test/sitemap.xml')
        ->assertOk()
        ->assertSee('<lastmod>2026-04-02</lastmod>', false);
});

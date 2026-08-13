<?php

use App\Services\Stories\StoryRepository;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->storiesPath = storage_path('framework/testing/stories-'.uniqid());
    File::makeDirectory($this->storiesPath, recursive: true);
    config()->set('stories.path', $this->storiesPath);
});

afterEach(function () {
    File::deleteDirectory($this->storiesPath);
});

/**
 * @param  array<string, mixed>  $frontMatter
 */
function writeStory(string $slug, array $frontMatter = [], string $body = 'Hello from the story body.'): void
{
    $yaml = Yaml::dump(array_merge([
        'title' => str($slug)->headline()->value(),
        'date' => '2026-01-15',
        'excerpt' => 'A short excerpt for '.$slug.'.',
        'published' => true,
    ], $frontMatter));

    File::put(test()->storiesPath."/{$slug}.md", "---\n{$yaml}---\n\n{$body}\n");
}

function actLikeProduction(): void
{
    app()->detectEnvironment(fn () => 'production');
}

it('renders the index in reverse-chronological order', function () {
    writeStory('oldest-post', ['date' => '2026-01-01']);
    writeStory('newest-post', ['date' => '2026-03-01']);
    writeStory('middle-post', ['date' => '2026-02-01']);

    $this->get('http://plateful.test/stories')
        ->assertOk()
        ->assertSeeInOrder(['Newest Post', 'Middle Post', 'Oldest Post'])
        ->assertSee('A short excerpt for oldest-post.');
});

it('renders an article with its markdown, byline, and date', function () {
    writeStory('taco-shop', ['date' => '2026-05-04'], "## The early days\n\nIt started with **one truck**.");

    $this->get('http://plateful.test/stories/taco-shop')
        ->assertOk()
        ->assertSee('Taco Shop')
        ->assertSee('<h2>The early days</h2>', false)
        ->assertSee('<strong>one truck</strong>', false)
        ->assertSee('Taylor Remund, founder of Plateful')
        ->assertSee('May 4, 2026');
});

it('ships full SEO head tags on an article', function () {
    writeStory('seo-post', ['excerpt' => 'The meta description.', 'hero' => '/images/stories/seo-post.jpg']);

    $this->get('http://plateful.test/stories/seo-post')
        ->assertOk()
        ->assertSee('<title>Seo Post | Plateful Stories</title>', false)
        ->assertSee('<meta name="description" content="The meta description.">', false)
        ->assertSee('<link rel="canonical" href="http://plateful.test/stories/seo-post">', false)
        ->assertSee('<meta property="og:type" content="article">', false)
        ->assertSee('<meta property="og:image" content="http://plateful.test/images/stories/seo-post.jpg">', false)
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false);
});

it('embeds schema.org Article JSON-LD on an article', function () {
    writeStory('json-ld-post');

    $response = $this->get('http://plateful.test/stories/json-ld-post')->assertOk();

    preg_match('/<script type="application\/ld\+json">(.+?)<\/script>/s', $response->getContent(), $matches);

    expect($matches)->toHaveCount(2);

    $jsonLd = json_decode($matches[1], associative: true);

    expect($jsonLd)
        ->{'@type'}->toBe('Article')
        ->headline->toBe('Json Ld Post')
        ->author->name->toBe('Taylor Remund, founder of Plateful')
        ->mainEntityOfPage->toBe('http://plateful.test/stories/json-ld-post');
});

it('returns 404 for an unknown slug', function () {
    $this->get('http://plateful.test/stories/no-such-story')->assertNotFound();
});

it('shows drafts locally with a draft badge', function () {
    writeStory('work-in-progress', ['published' => false]);

    $this->get('http://plateful.test/stories')
        ->assertOk()
        ->assertSee('Work In Progress')
        ->assertSee('Draft');

    $this->get('http://plateful.test/stories/work-in-progress')
        ->assertOk()
        ->assertSee('noindex');
});

it('hides unpublished posts in production', function () {
    writeStory('secret-draft', ['published' => false]);
    actLikeProduction();

    $this->get('http://plateful.test/stories')->assertOk()->assertDontSee('Secret Draft');
    $this->get('http://plateful.test/stories/secret-draft')->assertNotFound();
});

it('hides future-dated posts in production even when published', function () {
    writeStory('scheduled-post', ['date' => '2100-01-01']);
    actLikeProduction();

    $this->get('http://plateful.test/stories')->assertOk()->assertDontSee('Scheduled Post');
    $this->get('http://plateful.test/stories/scheduled-post')->assertNotFound();
});

it('never renders the template file', function () {
    File::copy(base_path('content/stories/TEMPLATE.md'), test()->storiesPath.'/TEMPLATE.md');

    $this->get('http://plateful.test/stories')->assertOk()->assertDontSee('Your headline here');
    $this->get('http://plateful.test/stories/template')->assertNotFound();
});

it('serves a valid RSS feed of live stories only', function () {
    writeStory('published-post');
    writeStory('draft-post', ['published' => false]);

    $response = $this->get('http://plateful.test/stories/feed')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/rss+xml; charset=UTF-8');

    $feed = simplexml_load_string($response->getContent());

    expect($feed)->not->toBeFalse()
        ->and($feed->channel->title)->toEqual('Plateful Stories')
        ->and($feed->channel->item)->toHaveCount(1)
        ->and((string) $feed->channel->item[0]->link)->toBe('http://plateful.test/stories/published-post');
});

it('parses the committed seed content', function () {
    config()->set('stories.path', base_path('content/stories'));

    $stories = app(StoryRepository::class)->all();

    $seed = $stories->firstWhere('slug', 'why-im-building-plateful');

    expect($seed)->not->toBeNull()
        ->and($seed->title)->toBe("Why I'm building Plateful")
        ->and($seed->published)->toBeFalse()
        ->and($seed->excerpt)->not->toBe('');
});

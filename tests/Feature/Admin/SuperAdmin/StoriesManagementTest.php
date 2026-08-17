<?php

use App\Models\StoryPublishOverride;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

const SUPER_ADMIN_STORIES_BASE = 'http://admin.plateful.test';

beforeEach(function () {
    config(['platform.primary_domain' => 'plateful.test']);

    $this->storiesPath = storage_path('framework/testing/admin-stories-'.uniqid());
    File::makeDirectory($this->storiesPath, recursive: true);
    config()->set('stories.path', $this->storiesPath);
});

afterEach(function () {
    File::deleteDirectory($this->storiesPath);
});

function writeAdminStory(string $slug, bool $published, string $date = '2026-01-15'): void
{
    $yaml = Yaml::dump([
        'title' => str($slug)->headline()->value(),
        'date' => $date,
        'excerpt' => 'Excerpt.',
        'published' => $published,
    ]);

    File::put(test()->storiesPath."/{$slug}.md", "---\n{$yaml}---\n\nBody.\n");
}

test('super admin sees the stories page with file and override state', function () {
    writeAdminStory('live-story', published: true);
    writeAdminStory('draft-story', published: false, date: '2026-01-10');
    StoryPublishOverride::factory()->create(['slug' => 'draft-story', 'published' => true]);

    $response = $this->actingAs(User::factory()->superAdmin()->create())
        ->get(SUPER_ADMIN_STORIES_BASE.'/super/stories');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/SuperAdmin/Stories')
        ->has('stories', 2)
        ->where('stories.1.slug', 'draft-story')
        ->where('stories.1.filePublished', false)
        ->where('stories.1.overridePublished', true)
        ->where('stories.1.isLive', true));
});

test('non-super admin cannot reach the stories page', function () {
    $this->actingAs(User::factory()->admin()->create())
        ->get(SUPER_ADMIN_STORIES_BASE.'/super/stories')
        ->assertForbidden();
});

test('publishing a draft creates an override row', function () {
    writeAdminStory('coming-soon', published: false);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(SUPER_ADMIN_STORIES_BASE.'/super/stories/coming-soon', ['published' => true])
        ->assertRedirect();

    expect(StoryPublishOverride::query()->where('slug', 'coming-soon')->first())
        ->not->toBeNull()
        ->published->toBeTrue();
});

test('setting a story back to its file state deletes the override', function () {
    writeAdminStory('coming-soon', published: false);
    StoryPublishOverride::factory()->create(['slug' => 'coming-soon', 'published' => true]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(SUPER_ADMIN_STORIES_BASE.'/super/stories/coming-soon', ['published' => false])
        ->assertRedirect();

    expect(StoryPublishOverride::query()->where('slug', 'coming-soon')->exists())->toBeFalse();
});

test('updating an unknown slug returns 404', function () {
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put(SUPER_ADMIN_STORIES_BASE.'/super/stories/no-such-story', ['published' => true])
        ->assertNotFound();
});

test('an override that agrees with the file is cleaned up on page load', function () {
    writeAdminStory('caught-up', published: true);
    StoryPublishOverride::factory()->create(['slug' => 'caught-up', 'published' => true]);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->get(SUPER_ADMIN_STORIES_BASE.'/super/stories')
        ->assertOk();

    expect(StoryPublishOverride::query()->where('slug', 'caught-up')->exists())->toBeFalse();
});

test('a publish override makes a deployed draft live on the public site in production', function () {
    writeAdminStory('flipped-live', published: false);
    StoryPublishOverride::factory()->create(['slug' => 'flipped-live', 'published' => true]);
    app()->detectEnvironment(fn () => 'production');

    $this->get('http://plateful.test/stories/flipped-live')->assertOk();
    $this->get('http://plateful.test/stories')->assertOk()->assertSee('Flipped Live');
    $this->get('http://plateful.test/sitemap.xml')->assertOk()->assertSee('/stories/flipped-live');
});

test('an unpublish override pulls a published story in production', function () {
    writeAdminStory('pulled-story', published: true);
    StoryPublishOverride::factory()->unpublished()->create(['slug' => 'pulled-story']);
    app()->detectEnvironment(fn () => 'production');

    $this->get('http://plateful.test/stories/pulled-story')->assertNotFound();
    $this->get('http://plateful.test/stories')->assertOk()->assertDontSee('Pulled Story');
    $this->get('http://plateful.test/stories/feed')->assertOk()->assertDontSee('pulled-story');
});

test('a publish override does not make a future-dated story live early', function () {
    writeAdminStory('scheduled', published: false, date: '2100-01-01');
    StoryPublishOverride::factory()->create(['slug' => 'scheduled', 'published' => true]);
    app()->detectEnvironment(fn () => 'production');

    $this->get('http://plateful.test/stories/scheduled')->assertNotFound();
});

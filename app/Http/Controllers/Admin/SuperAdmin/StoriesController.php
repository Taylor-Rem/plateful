<?php

namespace App\Http\Controllers\Admin\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\StoryPublishOverride;
use App\Services\Stories\Story;
use App\Services\Stories\StoryRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Publish / unpublish the flat-file stories without a deploy. The markdown
 * files in git remain the source of truth for content and the default
 * publish state; this page only manages story_publish_overrides rows that
 * take precedence over the file's front matter.
 */
class StoriesController extends Controller
{
    public function __construct(public StoryRepository $stories) {}

    public function index(): Response
    {
        // An override that agrees with the file's front matter is redundant —
        // the file caught up (published state was committed), so drop the row
        // rather than showing a stale "overridden" flag forever.
        $stories = $this->stories->all();

        StoryPublishOverride::query()
            ->get()
            ->filter(function (StoryPublishOverride $override) use ($stories) {
                $story = $stories->firstWhere('slug', $override->slug);

                return $story === null || $story->published === $override->published;
            })
            ->each->delete();

        $rows = $this->stories->all()->map(fn (Story $story) => [
            'slug' => $story->slug,
            'title' => $story->title,
            'date' => $story->date->toDateString(),
            'tags' => $story->tags,
            'filePublished' => $story->published,
            'overridePublished' => $story->publishedOverride,
            'isLive' => $story->isLive(),
            'isFutureDated' => $story->date->isFuture(),
            'url' => route('stories.show', $story->slug),
        ]);

        return Inertia::render('Admin/SuperAdmin/Stories', ['stories' => $rows]);
    }

    public function update(Request $request, string $slug): RedirectResponse
    {
        $validated = $request->validate(['published' => ['required', 'boolean']]);

        $story = $this->stories->find($slug);

        abort_if($story === null, 404);

        if ($story->published === (bool) $validated['published']) {
            // Back to what the file says — no override needed.
            StoryPublishOverride::query()->where('slug', $slug)->delete();
        } else {
            StoryPublishOverride::query()->updateOrCreate(
                ['slug' => $slug],
                ['published' => (bool) $validated['published']],
            );
        }

        return back();
    }
}

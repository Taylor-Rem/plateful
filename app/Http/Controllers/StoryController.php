<?php

namespace App\Http\Controllers;

use App\Services\Stories\StoryRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/**
 * The Stories publication — server-rendered Blade (no Inertia) so search
 * engines get complete head/meta/body HTML with zero client-side rendering.
 */
class StoryController extends Controller
{
    public function __construct(public StoryRepository $stories) {}

    public function index(): View
    {
        $stories = $this->includeDrafts()
            ? $this->stories->all()
            : $this->stories->live();

        return view('stories.index', ['stories' => $stories]);
    }

    public function show(string $slug): View
    {
        $story = $this->stories->find($slug);

        abort_if($story === null, 404);
        abort_if(! $story->isLive() && ! $this->includeDrafts(), 404);

        return view('stories.show', ['story' => $story]);
    }

    public function feed(): Response
    {
        return response()
            ->view('stories.feed', ['stories' => $this->stories->live()])
            ->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    /**
     * Drafts (unpublished or future-dated) render everywhere except
     * production so posts can be proofread at a real URL before launch.
     */
    private function includeDrafts(): bool
    {
        return ! app()->environment('production');
    }
}

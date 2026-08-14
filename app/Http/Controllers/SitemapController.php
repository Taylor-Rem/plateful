<?php

namespace App\Http\Controllers;

use App\Services\Stories\StoryRepository;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    /**
     * Root-domain sitemap: the static marketing pages plus every live story.
     * Drafts and future-dated posts are never listed, in any environment.
     */
    public function __invoke(StoryRepository $stories): Response
    {
        $pages = [
            route('home'),
            route('owner-signup.landing'),
            route('savings'),
            route('support'),
            route('stories.index'),
        ];

        return response()
            ->view('sitemap', ['pages' => $pages, 'stories' => $stories->live()])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}

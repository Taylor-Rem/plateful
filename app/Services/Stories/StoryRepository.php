<?php

namespace App\Services\Stories;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use SplFileInfo;
use Throwable;

/**
 * Reads stories from the flat-file content directory (config('stories.path')).
 *
 * Parsed markdown is cached per file, keyed on the file's mtime, so editing a
 * file busts its own cache entry and untouched files never re-parse.
 */
class StoryRepository
{
    /**
     * Every parseable story, newest first — including drafts.
     *
     * @return Collection<int, Story>
     */
    public function all(): Collection
    {
        $path = config('stories.path');

        if (! File::isDirectory($path)) {
            return collect();
        }

        return collect(File::files($path))
            ->filter(fn (SplFileInfo $file) => str_ends_with($file->getFilename(), '.md')
                && $file->getFilename() !== 'TEMPLATE.md')
            ->map(fn (SplFileInfo $file) => $this->parse($file))
            ->filter()
            ->sortByDesc(fn (Story $story) => $story->date)
            ->values();
    }

    /**
     * Published, non-future-dated stories, newest first.
     *
     * @return Collection<int, Story>
     */
    public function live(): Collection
    {
        return $this->all()->filter(fn (Story $story) => $story->isLive())->values();
    }

    /**
     * Resolved through all() rather than a direct file lookup so slug matching
     * stays case-sensitive on case-insensitive filesystems (macOS would happily
     * serve TEMPLATE.md for /stories/template) and the TEMPLATE exclusion
     * applies in one place. Parsing is cached, so this stays cheap.
     */
    public function find(string $slug): ?Story
    {
        return $this->all()->firstWhere('slug', $slug);
    }

    private function parse(SplFileInfo $file): ?Story
    {
        $slug = $file->getBasename('.md');

        try {
            $compiled = Cache::remember(
                'stories:'.md5($file->getPathname()).":{$file->getMTime()}",
                now()->addWeek(),
                fn (): array => $this->compile($file->getPathname()),
            );
        } catch (Throwable $e) {
            // A malformed file must not take down the whole index in
            // production; locally it should fail loudly so it gets fixed.
            if (! app()->environment('production')) {
                throw $e;
            }

            report($e);

            return null;
        }

        if (blank($compiled['title'])) {
            return null;
        }

        return new Story(
            slug: $slug,
            title: $compiled['title'],
            date: CarbonImmutable::parse($compiled['date']),
            excerpt: $compiled['excerpt'],
            heroImage: $compiled['heroImage'],
            author: $compiled['author'],
            tags: $compiled['tags'],
            published: $compiled['published'],
            html: $compiled['html'],
        );
    }

    /**
     * @return array{title: ?string, date: string, excerpt: string, heroImage: ?string, author: string, tags: list<string>, published: bool, html: string}
     */
    private function compile(string $pathname): array
    {
        $result = $this->converter()->convert(File::get($pathname));

        $frontMatter = $result instanceof RenderedContentWithFrontMatter
            ? $result->getFrontMatter()
            : [];

        return [
            'title' => $frontMatter['title'] ?? null,
            'date' => $this->normalizeDate($frontMatter['date'] ?? null)->toIso8601String(),
            'excerpt' => (string) ($frontMatter['excerpt'] ?? ''),
            'heroImage' => $frontMatter['hero'] ?? null,
            'author' => $frontMatter['author'] ?? Story::DEFAULT_AUTHOR,
            'tags' => array_values(array_map(strval(...), (array) ($frontMatter['tags'] ?? []))),
            'published' => (bool) ($frontMatter['published'] ?? false),
            'html' => $result->getContent(),
        ];
    }

    /**
     * Symfony YAML turns an unquoted `2026-08-13` into a UTC unix timestamp;
     * quoted dates arrive as strings. Normalize both to midnight UTC so a
     * story dated "today" is live all day regardless of server timezone.
     */
    private function normalizeDate(mixed $value): CarbonImmutable
    {
        return match (true) {
            is_int($value) => CarbonImmutable::createFromTimestampUTC($value),
            is_string($value) => CarbonImmutable::parse($value, 'UTC'),
            $value instanceof \DateTimeInterface => CarbonImmutable::instance($value),
            default => CarbonImmutable::now('UTC')->startOfDay(),
        };
    }

    private function converter(): MarkdownConverter
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new FrontMatterExtension);

        return new MarkdownConverter($environment);
    }
}

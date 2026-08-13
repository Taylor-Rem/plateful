<?php

namespace App\Services\Stories;

use Carbon\CarbonImmutable;

class Story
{
    public const DEFAULT_AUTHOR = 'Taylor Remund, founder of Plateful';

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $slug,
        public string $title,
        public CarbonImmutable $date,
        public string $excerpt,
        public ?string $heroImage,
        public string $author,
        public array $tags,
        public bool $published,
        public string $html,
    ) {}

    /**
     * Publicly visible: published and not future-dated. Anything else is a
     * draft — hidden in production, visible locally for proofreading.
     */
    public function isLive(): bool
    {
        return $this->published && ! $this->date->isFuture();
    }

    public function heroImageUrl(): ?string
    {
        if ($this->heroImage === null) {
            return null;
        }

        return str_starts_with($this->heroImage, 'http')
            ? $this->heroImage
            : url($this->heroImage);
    }
}

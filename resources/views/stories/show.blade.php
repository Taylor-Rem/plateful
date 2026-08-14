@php
    $canonicalUrl = route('stories.show', $story->slug);

    $jsonLd = array_filter([
        '@context' => 'https://schema.org',
        '@type' => 'Article',
        'headline' => $story->title,
        'description' => $story->excerpt,
        'datePublished' => $story->date->toIso8601String(),
        'dateModified' => $story->date->toIso8601String(),
        'author' => [
            '@type' => 'Person',
            'name' => $story->author,
        ],
        'publisher' => [
            '@type' => 'Organization',
            'name' => 'Plateful',
            'url' => route('home'),
        ],
        'mainEntityOfPage' => $canonicalUrl,
        'image' => $story->heroImageUrl(),
    ]);
@endphp

<x-marketing.layout
    :title="$story->title.' | Plateful Stories'"
    :meta-description="$story->excerpt"
    :canonical-url="$canonicalUrl"
    og-type="article"
    :og-image="$story->heroImageUrl()"
>
    <x-slot:head>
        @unless ($story->isLive())
            <meta name="robots" content="noindex">
        @endunless
        <script type="application/ld+json">@json($jsonLd, JSON_UNESCAPED_SLASHES)</script>
    </x-slot:head>

    <article class="mx-auto max-w-3xl px-6 py-14 sm:py-20">
        <header>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                @foreach ($story->tags as $tag)
                    <span class="rounded-full bg-teal-700/10 px-2.5 py-1 font-semibold text-teal-700 capitalize">{{ $tag }}</span>
                @endforeach
                @unless ($story->isLive())
                    <span class="rounded-full bg-crimson-100 px-2.5 py-1 font-semibold text-crimson-700">Draft — not visible in production</span>
                @endunless
            </div>

            <h1 class="mt-5 text-4xl leading-[1.1] font-bold tracking-tight text-stone-900 sm:text-5xl">
                {{ $story->title }}
            </h1>

            <p class="mt-5 text-sm text-stone-500">
                By <span class="font-semibold text-stone-700">{{ $story->author }}</span>
                ·
                <time datetime="{{ $story->date->toDateString() }}">{{ $story->date->format('F j, Y') }}</time>
            </p>
        </header>

        @if ($story->heroImage)
            <img
                src="{{ $story->heroImage }}"
                alt=""
                class="mt-10 aspect-[16/9] w-full rounded-2xl object-cover shadow-md shadow-stone-900/10"
            >
        @endif

        <div class="story-prose mt-10">
            {!! $story->html !!}
        </div>

        {{-- Single soft CTA — one link, not an ad break. --}}
        <aside class="mt-14 rounded-2xl bg-white p-7 ring-1 ring-stone-900/5">
            <p class="text-sm leading-relaxed text-stone-600">
                Plateful gives independent restaurants their own online ordering at 4% per
                order — no subscriptions, no 30% commissions.
                <a href="{{ route('owner-signup.landing') }}" class="font-semibold text-teal-700 underline-offset-4 hover:underline">
                    See how it works</a>.
            </p>
        </aside>
    </article>
</x-marketing.layout>

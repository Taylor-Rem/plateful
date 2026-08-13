<x-marketing.layout
    title="Stories — Utah's Independent Restaurants | Plateful"
    meta-description="Profiles of Utah's independent restaurant owners, and plain-English explainers on online ordering and delivery-app economics."
    :canonical-url="route('stories.index')"
>
    <section class="relative overflow-hidden">
        <div aria-hidden="true" class="pointer-events-none absolute inset-0">
            <div class="absolute -top-32 -left-24 h-96 w-96 rounded-full bg-teal-100/70 blur-3xl"></div>
            <div class="absolute -right-24 bottom-0 h-80 w-80 rounded-full bg-crimson-100/40 blur-3xl"></div>
        </div>

        <div class="relative mx-auto max-w-6xl px-6 pt-16 pb-12 sm:pt-24 sm:pb-16">
            <p class="text-sm font-semibold tracking-widest text-crimson-600 uppercase">Stories</p>
            <h1 class="mt-4 max-w-2xl text-4xl leading-[1.1] font-bold tracking-tight text-stone-900 sm:text-5xl">
                Utah's independent restaurants, in their own words
            </h1>
            <p class="mt-5 max-w-xl text-lg leading-relaxed text-stone-600">
                Owner profiles from around the state, plus plain-English explainers on
                online ordering and what delivery apps really cost.
            </p>
        </div>
    </section>

    <section class="border-t border-stone-900/5 bg-white py-14 sm:py-16">
        <div class="mx-auto max-w-6xl px-6">
            @if ($stories->isEmpty())
                <p class="text-stone-600">No stories yet — check back soon.</p>
            @else
                <div class="grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($stories as $story)
                        <article class="group flex flex-col overflow-hidden rounded-2xl bg-cream/70 ring-1 ring-stone-900/5 transition hover:ring-stone-900/10">
                            <a href="{{ route('stories.show', $story->slug) }}" class="flex grow flex-col">
                                @if ($story->heroImage)
                                    <img
                                        src="{{ $story->heroImage }}"
                                        alt=""
                                        loading="lazy"
                                        class="aspect-[16/9] w-full object-cover"
                                    >
                                @endif
                                <div class="flex grow flex-col p-6">
                                    <div class="flex items-center gap-2 text-xs text-stone-500">
                                        <time datetime="{{ $story->date->toDateString() }}">{{ $story->date->format('F j, Y') }}</time>
                                        @unless ($story->isLive())
                                            <span class="rounded-full bg-crimson-100 px-2 py-0.5 font-semibold text-crimson-700">Draft</span>
                                        @endunless
                                    </div>
                                    <h2 class="mt-3 text-lg leading-snug font-semibold text-stone-900 group-hover:text-teal-700">
                                        {{ $story->title }}
                                    </h2>
                                    @if ($story->excerpt)
                                        <p class="mt-2 text-sm leading-relaxed text-stone-600">{{ $story->excerpt }}</p>
                                    @endif
                                </div>
                            </a>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </section>
</x-marketing.layout>

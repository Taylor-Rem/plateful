{{--
    Server-rendered twin of resources/js/layouts/MarketingLayout.vue for the
    Stories publication. Plain Blade (no Inertia) so crawlers get complete
    head/meta/body HTML with zero client-side rendering dependency.
--}}
@props([
    'title',
    'metaDescription',
    'canonicalUrl',
    'ogType' => 'website',
    'ogImage' => null,
])

@php
    $adminUrl = request()->getScheme().'://admin.'.config('platform.primary_domain');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#057575">

        <title>{{ $title }}</title>
        <meta name="description" content="{{ $metaDescription }}">
        <link rel="canonical" href="{{ $canonicalUrl }}">

        {{-- Open Graph (Facebook, iMessage, LinkedIn, Slack, Discord, …) --}}
        <meta property="og:type" content="{{ $ogType }}">
        <meta property="og:site_name" content="Plateful">
        <meta property="og:title" content="{{ $title }}">
        <meta property="og:description" content="{{ $metaDescription }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        @if ($ogImage)
            <meta property="og:image" content="{{ $ogImage }}">
        @endif

        {{-- Twitter Card --}}
        <meta name="twitter:card" content="{{ $ogImage ? 'summary_large_image' : 'summary' }}">
        <meta name="twitter:title" content="{{ $title }}">
        <meta name="twitter:description" content="{{ $metaDescription }}">
        @if ($ogImage)
            <meta name="twitter:image" content="{{ $ogImage }}">
        @endif

        <link rel="alternate" type="application/rss+xml" title="Plateful Stories" href="{{ route('stories.feed') }}">

        {{ $head ?? '' }}

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite('resources/css/app.css')
    </head>
    <body class="font-sans antialiased">
        <div class="min-h-screen bg-cream text-stone-900">
            <header class="sticky top-0 z-40 border-b border-stone-900/5 bg-cream/85 backdrop-blur-md">
                <div class="mx-auto flex max-w-6xl items-center justify-between px-6 py-4">
                    <a href="{{ route('home') }}" class="flex items-center">
                        <x-app-wordmark class="h-8 w-auto" />
                    </a>

                    <nav class="flex items-center gap-1 text-sm">
                        <a href="{{ route('stories.index') }}" class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block">Stories</a>
                        <a href="{{ route('owner-signup.landing') }}" class="hidden rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900 sm:inline-block">For restaurants</a>
                        <a href="{{ $adminUrl }}/login" class="rounded-full px-3.5 py-2 font-medium text-stone-600 transition hover:bg-stone-900/5 hover:text-stone-900">Sign in</a>
                        <a href="{{ route('owner-signup.create') }}" class="ml-2 inline-flex items-center rounded-full bg-teal-700 px-4 py-2 font-medium text-white shadow-sm transition hover:bg-teal-800">Get started</a>
                    </nav>
                </div>
            </header>

            <main>
                {{ $slot }}
            </main>

            <footer class="bg-teal-950 py-14 text-teal-100/70">
                <div class="mx-auto max-w-6xl px-6">
                    <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-[1.4fr_1fr_1fr_1fr]">
                        <div>
                            <p class="text-lg font-bold tracking-tight text-white">plateful</p>
                            <p class="mt-3 max-w-xs text-sm leading-relaxed">
                                Direct online ordering for independent restaurants. No middlemen, no 30% commissions.
                            </p>
                        </div>

                        <nav aria-label="For diners" class="text-sm">
                            <p class="text-xs font-semibold tracking-wider text-white/90 uppercase">For diners</p>
                            <ul class="mt-4 space-y-2.5">
                                <li><a href="{{ route('home') }}#restaurants" class="transition hover:text-white">Find restaurants</a></li>
                                <li><a href="{{ route('home') }}#how-loyalty-works" class="transition hover:text-white">How loyalty works</a></li>
                            </ul>
                        </nav>

                        <nav aria-label="For restaurants" class="text-sm">
                            <p class="text-xs font-semibold tracking-wider text-white/90 uppercase">For restaurants</p>
                            <ul class="mt-4 space-y-2.5">
                                <li><a href="{{ route('owner-signup.landing') }}" class="transition hover:text-white">Why Plateful</a></li>
                                <li><a href="{{ route('owner-signup.landing') }}#pricing" class="transition hover:text-white">Pricing</a></li>
                                <li><a href="{{ route('owner-signup.create') }}" class="transition hover:text-white">Get started</a></li>
                            </ul>
                        </nav>

                        <nav aria-label="Company" class="text-sm">
                            <p class="text-xs font-semibold tracking-wider text-white/90 uppercase">Company</p>
                            <ul class="mt-4 space-y-2.5">
                                <li><a href="{{ route('stories.index') }}" class="transition hover:text-white">Stories</a></li>
                                <li><a href="{{ route('support') }}" class="transition hover:text-white">Support</a></li>
                                <li><a href="{{ route('terms') }}" class="transition hover:text-white">Terms</a></li>
                                <li><a href="{{ route('privacy') }}" class="transition hover:text-white">Privacy</a></li>
                            </ul>
                        </nav>
                    </div>

                    <p class="mt-12 border-t border-white/10 pt-6 text-xs text-teal-100/50">
                        © {{ now()->year }} Plateful. All rights reserved.
                    </p>
                </div>
            </footer>
        </div>
    </body>
</html>

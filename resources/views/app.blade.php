<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      data-appearance-context="{{ $appearanceContext ?? 'apex' }}"
      @class(['dark' => ($appearance ?? 'system') == 'dark' && ($appearanceContext ?? 'apex') !== 'tenant'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="{{ $brandPalette['primary'] ?? '#069494' }}">

        @isset($tenantSeo)
            @if ($tenantSeo['description'])
                <meta name="description" content="{{ $tenantSeo['description'] }}">
            @endif

            {{-- Open Graph (Facebook, iMessage, LinkedIn, Slack, Discord, …) --}}
            <meta property="og:type" content="restaurant">
            <meta property="og:site_name" content="{{ $tenantSeo['siteName'] }}">
            <meta property="og:title" content="{{ $tenantSeo['title'] }}">
            @if ($tenantSeo['description'])
                <meta property="og:description" content="{{ $tenantSeo['description'] }}">
            @endif
            <meta property="og:url" content="{{ $tenantSeo['url'] }}">
            @if ($tenantSeo['ogImage'])
                <meta property="og:image" content="{{ $tenantSeo['ogImage'] }}">
            @endif

            {{-- Twitter Card --}}
            <meta name="twitter:card" content="{{ $tenantSeo['ogImage'] ? 'summary_large_image' : 'summary' }}">
            <meta name="twitter:title" content="{{ $tenantSeo['title'] }}">
            @if ($tenantSeo['description'])
                <meta name="twitter:description" content="{{ $tenantSeo['description'] }}">
            @endif
            @if ($tenantSeo['ogImage'])
                <meta name="twitter:image" content="{{ $tenantSeo['ogImage'] }}">
            @endif
        @endisset

        {{-- Inline script to detect system dark mode preference and apply it immediately.
             Tenant storefronts opt out entirely — they always render in light mode. --}}
        <script>
            (function() {
                const context = '{{ $appearanceContext ?? "apex" }}';

                if (context === 'tenant') {
                    document.documentElement.classList.remove('dark');
                    return;
                }

                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        @isset($brandPalette)
            <style>
                :root {
                    --brand-primary: {{ $brandPalette['primary'] }};
                    --brand-primary-foreground: {{ $brandPalette['primaryForeground'] }};
                    --brand-secondary: {{ $brandPalette['secondary'] }};
                    --brand-secondary-foreground: {{ $brandPalette['secondaryForeground'] }};
                }
            </style>
        @endisset

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <title>{{ $tenantSeo['title'] ?? config('app.name', 'Laravel') }}</title>
        <x-inertia::head />
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>

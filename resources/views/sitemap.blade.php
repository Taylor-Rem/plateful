{{-- Concatenated so the literal <? sequence never appears in the template: with short_open_tag on, it would flip Blade's tokenizer into PHP mode. --}}
{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
@foreach ($pages as $page)
    <url>
        <loc>{{ $page }}</loc>
    </url>
@endforeach
@foreach ($stories as $story)
    <url>
        <loc>{{ route('stories.show', $story->slug) }}</loc>
        <lastmod>{{ $story->date->toDateString() }}</lastmod>
    </url>
@endforeach
</urlset>

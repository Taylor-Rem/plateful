{{-- Concatenated so the literal <? sequence never appears in the template: with short_open_tag on, it would flip Blade's tokenizer into PHP mode. --}}
{!! '<'.'?xml version="1.0" encoding="UTF-8"?'.'>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
    <channel>
        <title>Plateful Stories</title>
        <link>{{ route('stories.index') }}</link>
        <atom:link href="{{ route('stories.feed') }}" rel="self" type="application/rss+xml" />
        <description>Profiles of Utah's independent restaurant owners, and plain-English explainers on online ordering and delivery-app economics.</description>
        <language>en-us</language>
@foreach ($stories as $story)
        <item>
            <title>{{ $story->title }}</title>
            <link>{{ route('stories.show', $story->slug) }}</link>
            <guid>{{ route('stories.show', $story->slug) }}</guid>
            <pubDate>{{ $story->date->toRssString() }}</pubDate>
            <description>{{ $story->excerpt }}</description>
        </item>
@endforeach
    </channel>
</rss>

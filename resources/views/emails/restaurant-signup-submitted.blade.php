<!DOCTYPE html>
<html>
<body>
    <p>A new restaurant has applied to join Plateful.</p>

    <ul>
        <li><strong>Restaurant:</strong> {{ $signup->proposed_name }}</li>
        <li><strong>Subdomain:</strong> {{ $signup->proposed_subdomain }}</li>
        @if ($signup->proposed_custom_domain)
            <li><strong>Custom domain requested:</strong> {{ $signup->proposed_custom_domain }}</li>
        @endif
        @if ($signup->cuisine_type)
            <li><strong>Cuisine:</strong> {{ $signup->cuisine_type }}</li>
        @endif
        @if ($signup->city || $signup->state)
            <li><strong>Location:</strong> {{ trim($signup->city.' '.$signup->state) }}</li>
        @endif
        <li><strong>Owner:</strong> {{ $signup->user->name }} &lt;{{ $signup->user->email }}&gt;</li>
    </ul>

    @if ($signup->notes)
        <p><strong>Notes from the owner:</strong></p>
        <blockquote>{{ $signup->notes }}</blockquote>
    @endif

    <p><a href="{{ $reviewUrl }}">Review this signup</a></p>
</body>
</html>

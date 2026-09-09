<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h1 style="margin: 0 0 8px;">Campaign needs a human review</h1>
    <p style="color: #4b5563;">
        A campaign from <strong>{{ $restaurant->name }}</strong> ({{ $restaurant->subdomain }}) was
        held for review and could not be auto-approved. It waits until a super admin approves it.
    </p>

    <p style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px;">
        Subject: <strong>{{ $campaign->subject }}</strong><br>
        Audience: {{ $campaign->audience_filter['type'] ?? 'all' }}<br>
        @if ($campaign->scheduled_at)
            Requested send time: {{ $campaign->scheduled_at->toDayDateTimeString() }} UTC
        @else
            Requested: send immediately on approval
        @endif
    </p>

    @if ($campaign->review_notes)
        <p style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px;">
            Automated review: {{ $campaign->review_notes }}
        </p>
    @endif

    <p style="margin: 24px 0;">
        <a href="{{ $reviewUrl }}" style="background: #111827; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 8px; display: inline-block; font-weight: bold;">Review campaigns</a>
    </p>
</body>
</html>

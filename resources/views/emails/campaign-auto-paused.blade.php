<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h1 style="margin: 0 0 8px;">Campaign auto-paused on complaints</h1>
    <p style="color: #4b5563;">
        A campaign from <strong>{{ $restaurant->name }}</strong> ({{ $restaurant->subdomain }})
        crossed the complaint threshold. The campaign is halted mid-send and the restaurant's
        sending is paused until a super admin reviews it.
    </p>

    <p style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px;">
        Subject: <strong>{{ $campaign->subject }}</strong><br>
        Recipients: {{ $campaign->recipients_count }}<br>
        Complaints: {{ $campaign->complained_count }}
    </p>

    <p style="margin: 24px 0;">
        <a href="{{ $reviewUrl }}" style="background: #111827; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 8px; display: inline-block; font-weight: bold;">Review campaigns</a>
    </p>
</body>
</html>

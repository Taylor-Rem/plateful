<!DOCTYPE html>
<html>
<body>
    <p>Hi {{ $ownerName ?? 'there' }},</p>

    <p>Thanks for applying to bring <strong>{{ $restaurantName }}</strong> onto Plateful. After review, we're unable to approve your application at this time.</p>

    @if ($reason)
        <p><strong>From the team:</strong></p>
        <blockquote>{{ $reason }}</blockquote>
    @endif

    <p>Your Plateful account is still active — you can continue ordering from any Plateful restaurant. If you'd like to discuss your application, just reply to this email.</p>
</body>
</html>

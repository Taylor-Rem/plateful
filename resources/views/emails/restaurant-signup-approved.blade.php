<!DOCTYPE html>
<html>
<body>
    <p>Hi {{ $ownerName ?? 'there' }},</p>

    <p>Great news — your application for <strong>{{ $restaurantName }}</strong> has been approved.</p>

    <p>Head to the admin console to finish setting up your restaurant: menu, hours, Stripe, and going live.</p>

    <p><a href="{{ $adminUrl }}">{{ $adminUrl }}</a></p>

    <p>Welcome to Plateful.</p>
</body>
</html>

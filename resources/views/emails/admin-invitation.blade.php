<!DOCTYPE html>
<html>
<body>
    <p>Hi,</p>

    <p>
        {{ $inviterName }} invited you to manage
        {{ $restaurantName ?? 'the platform' }} on Plateful.
    </p>

    <p>
        <a href="{{ $acceptUrl }}">Accept invitation</a>
    </p>

    <p>If you didn't expect this, you can safely ignore this email.</p>
</body>
</html>

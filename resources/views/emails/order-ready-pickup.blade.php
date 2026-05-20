@php
    $primary = $restaurant?->primary_color ?: '#111827';
    $fmt = fn ($cents) => '$'.number_format(((int) $cents) / 100, 2);
@endphp
<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h1 style="color: {{ $primary }}; margin: 0 0 8px;">Your order is ready, {{ $order->customer_name }}!</h1>
    <p style="color: #4b5563;">Order #<strong style="font-family: monospace;">{{ $order->number }}</strong> is ready for pickup at <strong>{{ $restaurant?->name }}</strong>.</p>

    @if ($restaurant && ($restaurant->street || $restaurant->city))
        <h3 style="margin: 24px 0 8px;">Pickup at</h3>
        <p style="color: #4b5563; margin: 0;">
            {{ $restaurant->street }}<br>
            {{ $restaurant->city }}@if ($restaurant->state), {{ $restaurant->state }}@endif {{ $restaurant->postal_code }}
        </p>
    @endif

    <h3 style="margin: 24px 0 8px;">Your order</h3>
    <table style="width: 100%; border-collapse: collapse;">
        @foreach ($order->items as $line)
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                    <strong>{{ $line->quantity }}× {{ $line->name }}</strong>
                </td>
                <td style="padding: 8px 0; border-bottom: 1px solid #f3f4f6; text-align: right;">{{ $fmt($line->subtotal_cents) }}</td>
            </tr>
        @endforeach
        <tr>
            <td style="padding-top: 12px; font-weight: bold; color: {{ $primary }};">Total</td>
            <td style="padding-top: 12px; text-align: right; font-weight: bold; color: {{ $primary }};">{{ $fmt($order->total_cents) }}</td>
        </tr>
    </table>

    <p style="margin-top: 24px; color: #4b5563;">See you soon!</p>
</body>
</html>

@php
    $primary = $restaurant?->primary_color ?: '#111827';
    $fmt = fn ($cents) => '$'.number_format(((int) $cents) / 100, 2);
@endphp
<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h1 style="color: {{ $primary }}; margin: 0 0 8px;">We're sorry, {{ $order->customer_name }}.</h1>
    <p style="color: #4b5563;">Your order #<strong style="font-family: monospace;">{{ $order->number }}</strong> at <strong>{{ $restaurant?->name }}</strong> has been cancelled.</p>

    @if (! empty($reason))
        <p style="background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 12px 16px; color: #991b1b;">
            <strong>Reason:</strong> {{ $reason }}
        </p>
    @endif

    <h3 style="margin: 24px 0 8px;">Order summary</h3>
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

    <p style="margin-top: 24px; color: #4b5563;">If you have any questions, please reach out to {{ $restaurant?->name }} directly.</p>
</body>
</html>

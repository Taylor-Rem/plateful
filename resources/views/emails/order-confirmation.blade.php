@php
    $primary = $restaurant?->primary_color ?: '#111827';
    $fmt = fn ($cents) => '$'.number_format(((int) $cents) / 100, 2);
@endphp
<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 24px;">
    <h1 style="color: {{ $primary }}; margin: 0 0 8px;">Thanks for your order, {{ $order->customer_name }}!</h1>
    <p style="color: #4b5563;">We received your order at <strong>{{ $restaurant?->name }}</strong>.</p>

    <p style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; font-size: 18px;">
        Order number: <strong style="font-family: monospace;">{{ $order->number }}</strong><br>
        Type: <strong>{{ ucfirst($order->type->value) }}</strong>
    </p>

    @if ($order->type->value === 'delivery' && $order->delivery_address)
        <h3 style="margin: 24px 0 8px;">Delivery to</h3>
        <p style="color: #4b5563; margin: 0;">
            {{ $order->delivery_address['street'] }}<br>
            @if (! empty($order->delivery_address['street2']))
                {{ $order->delivery_address['street2'] }}<br>
            @endif
            {{ $order->delivery_address['city'] }}, {{ $order->delivery_address['state'] }} {{ $order->delivery_address['postal_code'] }}
        </p>
        @if (! empty($order->delivery_address['instructions']))
            <p style="color: #6b7280; margin-top: 4px;">Notes: {{ $order->delivery_address['instructions'] }}</p>
        @endif
    @endif

    <h3 style="margin: 24px 0 8px;">Items</h3>
    <table style="width: 100%; border-collapse: collapse;">
        @foreach ($order->items as $line)
            <tr>
                <td style="padding: 8px 0; border-bottom: 1px solid #f3f4f6;">
                    <strong>{{ $line->quantity }}× {{ $line->name }}</strong>
                    @php
                        $sels = [];
                        if (is_array($line->modifiers) && isset($line->modifiers['groups'])) {
                            foreach ($line->modifiers['groups'] as $g) {
                                foreach ($g['selections'] ?? [] as $s) {
                                    $sels[] = $s['option_name'] ?? '';
                                }
                            }
                        }
                    @endphp
                    @if ($sels)
                        <div style="color: #6b7280; font-size: 13px;">{{ implode(' · ', $sels) }}</div>
                    @endif
                </td>
                <td style="padding: 8px 0; border-bottom: 1px solid #f3f4f6; text-align: right;">{{ $fmt($line->subtotal_cents) }}</td>
            </tr>
        @endforeach
    </table>

    <table style="width: 100%; margin-top: 16px;">
        <tr><td style="color: #6b7280;">Subtotal</td><td style="text-align: right;">{{ $fmt($order->subtotal_cents) }}</td></tr>
        <tr><td style="color: #6b7280;">Tax</td><td style="text-align: right;">{{ $fmt($order->tax_cents) }}</td></tr>
        @if ($order->delivery_fee_cents > 0)
            <tr><td style="color: #6b7280;">Delivery fee</td><td style="text-align: right;">{{ $fmt($order->delivery_fee_cents) }}</td></tr>
        @endif
        @if ($order->tip_cents > 0)
            <tr><td style="color: #6b7280;">Tip</td><td style="text-align: right;">{{ $fmt($order->tip_cents) }}</td></tr>
        @endif
        <tr><td style="font-weight: bold; padding-top: 8px; color: {{ $primary }};">Total</td>
            <td style="font-weight: bold; text-align: right; padding-top: 8px; color: {{ $primary }};">{{ $fmt($order->total_cents) }}</td>
        </tr>
    </table>

    @if ($order->notes)
        <p style="margin-top: 16px; color: #6b7280;"><em>Notes: {{ $order->notes }}</em></p>
    @endif

    <p style="margin-top: 24px; color: #4b5563;">We'll email you again when your order is ready.</p>
</body>
</html>

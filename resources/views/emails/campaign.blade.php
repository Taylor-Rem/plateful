@php
    $primary = $restaurant?->primary_color ?: '#111827';
    $ctaLabel = $campaign->cta_label ?: 'Order now';
    $ctaUrl = $campaign->cta_url ?: $restaurant->publicUrl();
    $address = collect([
        $restaurant->street,
        $restaurant->street2,
        trim(collect([$restaurant->city, $restaurant->state])->filter()->implode(', ').' '.$restaurant->postal_code),
    ])->filter()->implode(', ');
@endphp
<!DOCTYPE html>
<html>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #111827; max-width: 600px; margin: 0 auto; padding: 24px;">
    @if ($campaign->preheader)
        <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">{{ $campaign->preheader }}</div>
    @endif

    @if ($restaurant->logoUrl())
        <img src="{{ $restaurant->logoUrl() }}" alt="{{ $restaurant->name }}" style="max-height: 64px; max-width: 200px; margin-bottom: 16px;">
    @endif

    <h1 style="color: {{ $primary }}; margin: 0 0 12px;">{{ $campaign->headline }}</h1>

    <div style="color: #4b5563; line-height: 1.6;">{!! nl2br(e($campaign->body)) !!}</div>

    @if ($campaign->offer_callout)
        <p style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 16px; font-size: 18px; margin: 20px 0;">
            <strong>{{ $campaign->offer_callout }}</strong>
        </p>
    @endif

    <p style="margin: 24px 0;">
        <a href="{{ $ctaUrl }}" style="background: {{ $primary }}; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 8px; display: inline-block; font-weight: bold;">{{ $ctaLabel }}</a>
    </p>

    {{-- Compliance footer: platform-rendered, never restaurant-editable.
         Physical address + working unsubscribe link are CAN-SPAM requirements. --}}
    <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 32px 0 16px;">
    <p style="color: #6b7280; font-size: 12px; line-height: 1.6; margin: 0;">
        You're receiving this because you opted in to emails from {{ $restaurant->name }}.<br>
        {{ $restaurant->name }} · {{ $address }}<br>
        Sent via Plateful on behalf of {{ $restaurant->name }}.<br>
        <a href="{{ $unsubscribeUrl }}" style="color: #6b7280;">Unsubscribe</a> from these emails at any time.
    </p>
</body>
</html>

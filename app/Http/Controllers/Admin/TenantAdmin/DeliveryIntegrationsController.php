<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Enums\DeliveryFallbackAction;
use App\Enums\DeliveryFeeStrategy;
use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryMode;
use App\Enums\DeliveryProviderName;
use App\Enums\SelfDeliveryTipRecipient;
use App\Exceptions\DeliveryProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeliverySettingsRequest;
use App\Models\DeliveryIntegration;
use App\Models\Restaurant;
use App\Services\Delivery\DoorDash\DoorDashProvisioningService;
use App\Services\Delivery\UberDirect\UberDirectProvisioningService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-restaurant delivery integrations. Both courier networks are umbrella
 * integrations provisioned under Plateful's platform accounts, so there is
 * nothing to paste and no OAuth redirect: the owner enables delivery with one
 * click and Plateful registers the restaurant provider-side.
 */
class DeliveryIntegrationsController extends Controller
{
    public function show(Restaurant $restaurant): Response
    {
        $integrations = $restaurant->deliveryIntegrations()
            ->get()
            ->keyBy(fn (DeliveryIntegration $integration): string => $integration->provider->value);

        // Providers with a built adapter. Others render as "coming soon".
        $connectable = [DeliveryProviderName::DoorDash, DeliveryProviderName::Uber];

        return Inertia::render('Admin/TenantAdmin/DeliveryIntegrations', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'settings' => [
                'deliveryEnabled' => (bool) $restaurant->delivery_enabled,
                'deliveryMode' => $restaurant->delivery_mode?->value,
                'deliveryFee' => number_format((int) $restaurant->delivery_fee_cents / 100, 2, '.', ''),
                'deliveryFeeStrategy' => ($restaurant->delivery_fee_strategy ?? DeliveryFeeStrategy::PassThrough)->value,
                'prepTimeMinutes' => (int) $restaurant->prep_time_minutes,
                'selfDeliveryTipRecipient' => ($restaurant->self_delivery_tip_recipient ?? SelfDeliveryTipRecipient::Driver)->value,
                'deliveryFallbackAction' => ($restaurant->delivery_fallback_action ?? DeliveryFallbackAction::TryNextProvider)->value,
                // First entry of the provider chain — the network tried first
                // when more than one is connected. Mirrors the dispatcher's
                // default chain when no explicit priority is stored.
                'preferredCourier' => $restaurant->delivery_provider_priority[0] ?? DeliveryProviderName::DoorDash->value,
                'restrictedItemsAttestedAt' => $restaurant->restricted_items_attested_at?->toIso8601String(),
                'saveUrl' => route('admin.restaurant.delivery.settings.update', ['restaurant' => $restaurant->subdomain]),
            ],
            'options' => [
                'modes' => $this->enumOptions(DeliveryMode::cases()),
                'feeStrategies' => $this->enumOptions(DeliveryFeeStrategy::cases()),
                'tipRecipients' => $this->enumOptions(SelfDeliveryTipRecipient::cases()),
                'fallbackActions' => $this->enumOptions(DeliveryFallbackAction::cases()),
            ],
            'providers' => collect(DeliveryProviderName::cases())
                // Self-delivery is a delivery *mode*, not a credentialed
                // integration — it has nothing to connect.
                ->reject(fn (DeliveryProviderName $provider): bool => $provider === DeliveryProviderName::Self)
                ->map(function (DeliveryProviderName $provider) use ($integrations, $restaurant, $connectable): array {
                    $integration = $integrations[$provider->value] ?? null;
                    $available = in_array($provider, $connectable, strict: true);

                    return [
                        'provider' => $provider->value,
                        'label' => $provider->label(),
                        'status' => $integration?->status->value
                            ?? DeliveryIntegrationStatus::Disconnected->value,
                        'lastError' => $integration?->last_error,
                        'connectedAt' => $integration?->created_at?->toIso8601String(),
                        // The provisioned provider-side id, so the owner can
                        // quote it to support: Uber sub-org / DoorDash store.
                        'storeId' => $integration?->external_store_id ?? $integration?->customer_id,
                        'available' => $available,
                        'saveUrl' => $available
                            ? route("admin.restaurant.delivery.{$provider->value}.save", ['restaurant' => $restaurant->subdomain])
                            : null,
                        'disconnectUrl' => $available
                            ? route("admin.restaurant.delivery.{$provider->value}.disconnect", ['restaurant' => $restaurant->subdomain])
                            : null,
                    ];
                })
                // Providers you can actually connect lead; "coming soon" cards
                // sink. Enum order would otherwise put DoorDash first.
                ->sortByDesc('available')
                ->values()->all(),
        ]);
    }

    /**
     * The delivery behaviour flags. Every one of these existed in the schema
     * with no UI and no validation, which is why a restaurant could have
     * delivery on and no mode set, and nobody could tell.
     */
    public function updateSettings(DeliverySettingsRequest $request, Restaurant $restaurant): RedirectResponse
    {
        $validated = $request->validated();

        $restaurant->forceFill([
            'delivery_enabled' => (bool) $validated['delivery_enabled'],
            'delivery_mode' => $validated['delivery_mode'] ?? null,
            'delivery_fee_cents' => (int) ($request->input('delivery_fee_cents') ?? $restaurant->delivery_fee_cents),
            'delivery_fee_strategy' => $validated['delivery_fee_strategy'],
            'prep_time_minutes' => (int) $validated['prep_time_minutes'],
            'self_delivery_tip_recipient' => $validated['self_delivery_tip_recipient'],
            'delivery_fallback_action' => $validated['delivery_fallback_action'],
            // The preferred network leads the chain; the other stays as the
            // fallback the dispatcher tries next. Absent (single-network or
            // self-delivery saves) leaves the stored priority untouched.
            ...isset($validated['preferred_courier'])
                ? ['delivery_provider_priority' => $validated['preferred_courier'] === DeliveryProviderName::Uber->value
                    ? [DeliveryProviderName::Uber->value, DeliveryProviderName::DoorDash->value]
                    : [DeliveryProviderName::DoorDash->value, DeliveryProviderName::Uber->value]]
                : [],
            // First acceptance stamps the attestation; it is never un-stamped
            // by a later save (the record of who agreed must survive edits).
            ...$request->boolean('restricted_items_attested') && $restaurant->restricted_items_attested_at === null
                ? ['restricted_items_attested_at' => now()]
                : [],
        ])->save();

        return back()->with('success', 'Delivery settings saved.');
    }

    /**
     * @param  array<int, DeliveryMode|DeliveryFeeStrategy|SelfDeliveryTipRecipient|DeliveryFallbackAction>  $cases
     * @return array<int, array{value: string, label: string}>
     */
    private function enumOptions(array $cases): array
    {
        return array_map(fn ($case): array => [
            'value' => $case->value,
            'label' => $case->label(),
        ], $cases);
    }

    /**
     * One-click Uber Direct enablement, the twin of {@see enableDoorDash}.
     * Plateful provisions a sub-organization under its root Direct account
     * (centralized billing, silent invite) and stores its id. A failure is
     * parked on the integration row (status Error + reason) so the owner sees
     * why on the card.
     */
    public function enableUber(
        Restaurant $restaurant,
        UberDirectProvisioningService $provisioning,
    ): RedirectResponse {
        try {
            $provisioning->provisionOrganizationFor($restaurant);
        } catch (DeliveryProviderException $e) {
            DeliveryIntegration::updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'provider' => DeliveryProviderName::Uber,
                ],
                [
                    'status' => DeliveryIntegrationStatus::Error,
                    'last_error' => $e->getMessage(),
                ],
            );

            return back()->with('error', 'Could not enable Uber Direct delivery. Please try again.');
        }

        return back()->with('success', 'Uber Direct enabled.');
    }

    /**
     * Stop dispatching to the restaurant's Uber sub-organization. The org id is
     * deliberately KEPT: Uber orgs are permanent (only Uber can delete one), so
     * re-enabling must reuse the same org rather than provisioning a duplicate.
     */
    public function disconnectUber(Restaurant $restaurant): RedirectResponse
    {
        $restaurant->deliveryIntegrations()
            ->where('provider', DeliveryProviderName::Uber)
            ->get()
            ->each(fn (DeliveryIntegration $integration) => $integration->forceFill([
                'status' => DeliveryIntegrationStatus::Disconnected,
                'last_error' => null,
            ])->save());

        return back()->with('success', 'Uber Direct disconnected.');
    }

    /**
     * One-click DoorDash Drive enablement: Plateful provisions the restaurant's
     * Business + Store under its own platform account and stores the ids. A
     * failure is parked on the integration row (status Error + reason) so the
     * owner sees why on the card.
     */
    public function enableDoorDash(
        Restaurant $restaurant,
        DoorDashProvisioningService $provisioning,
    ): RedirectResponse {
        try {
            $provisioning->provisionStoreFor($restaurant);
        } catch (DeliveryProviderException $e) {
            DeliveryIntegration::updateOrCreate(
                [
                    'restaurant_id' => $restaurant->id,
                    'provider' => DeliveryProviderName::DoorDash,
                ],
                [
                    'status' => DeliveryIntegrationStatus::Error,
                    'last_error' => $e->getMessage(),
                ],
            );

            return back()->with('error', 'Could not enable DoorDash delivery. Please try again.');
        }

        return back()->with('success', 'DoorDash Drive enabled.');
    }

    /**
     * Forget the restaurant's DoorDash Store. The Business/Store may still exist
     * on DoorDash's side (re-enabling is idempotent), but Plateful stops
     * dispatching to it: supports() requires a stored external_store_id.
     */
    public function disconnectDoorDash(Restaurant $restaurant): RedirectResponse
    {
        $restaurant->deliveryIntegrations()
            ->where('provider', DeliveryProviderName::DoorDash)
            ->get()
            ->each(fn (DeliveryIntegration $integration) => $integration->forceFill([
                'external_business_id' => null,
                'external_store_id' => null,
                'status' => DeliveryIntegrationStatus::Disconnected,
                'last_error' => null,
            ])->save());

        return back()->with('success', 'DoorDash Drive disconnected.');
    }
}

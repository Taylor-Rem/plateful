<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UberDirectCredentialsRequest;
use App\Models\DeliveryIntegration;
use App\Models\Restaurant;
use App\Services\Delivery\UberDirect\UberDirectTokenService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-restaurant delivery credentials. Unlike POS, there is no OAuth redirect
 * to click through: Uber Direct is a client_credentials integration, so the
 * owner pastes credentials from their own Uber Direct dashboard.
 */
class DeliveryIntegrationsController extends Controller
{
    public function show(Restaurant $restaurant): Response
    {
        $integrations = $restaurant->deliveryIntegrations()
            ->get()
            ->keyBy(fn (DeliveryIntegration $integration): string => $integration->provider->value);

        // Providers with a built adapter. Others render as "coming soon".
        $connectable = [DeliveryProviderName::Uber];

        return Inertia::render('Admin/TenantAdmin/DeliveryIntegrations', [
            'restaurant' => RestaurantData::fromModel($restaurant),
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
                        // Never echo the client id/secret back — only enough to
                        // show the owner which account is wired up.
                        'customerId' => $integration?->customer_id,
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
     * Verify pasted Uber Direct credentials against the live token endpoint,
     * then store them. Verifying first means a typo fails here — in front of
     * the person who can fix it — rather than silently at dispatch time on a
     * customer's paid order.
     */
    public function saveUber(
        UberDirectCredentialsRequest $request,
        Restaurant $restaurant,
        UberDirectTokenService $tokens,
    ): RedirectResponse {
        $validated = $request->validated();

        try {
            $token = $tokens->requestToken($validated['client_id'], $validated['client_secret']);
        } catch (DeliveryProviderException $e) {
            return back()->withErrors(['client_id' => $e->getMessage()]);
        }

        DeliveryIntegration::updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'provider' => DeliveryProviderName::Uber,
            ],
            [
                'client_id' => $validated['client_id'],
                'client_secret' => $validated['client_secret'],
                'customer_id' => $validated['customer_id'],
                // The verification above already minted a usable token; keeping
                // it saves a redundant grant against the 100/hour limit.
                'access_token' => $token->accessToken,
                'token_expires_at' => $token->expiresAt,
                'status' => DeliveryIntegrationStatus::Connected,
                'last_error' => null,
            ],
        );

        return back()->with('success', 'Uber Direct connected.');
    }

    /**
     * Forget the restaurant's credentials. Note we cannot revoke the access
     * token the way the Square disconnect does — a client_credentials token is
     * minted from credentials the restaurant owns, so revoking is something
     * only they can do, by rotating the secret in their Uber dashboard.
     */
    public function disconnectUber(Restaurant $restaurant): RedirectResponse
    {
        $restaurant->deliveryIntegrations()
            ->where('provider', DeliveryProviderName::Uber)
            ->get()
            ->each(fn (DeliveryIntegration $integration) => $integration->forceFill([
                'client_id' => null,
                'client_secret' => null,
                'customer_id' => null,
                'access_token' => null,
                'token_expires_at' => null,
                'status' => DeliveryIntegrationStatus::Disconnected,
                'last_error' => null,
            ])->save());

        return back()->with('success', 'Uber Direct disconnected.');
    }
}

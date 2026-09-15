<?php

namespace App\Http\Controllers\Admin\TenantAdmin;

use App\Data\RestaurantData;
use App\Enums\PosIntegrationStatus;
use App\Enums\PosProviderName;
use App\Http\Controllers\Controller;
use App\Models\PosIntegration;
use App\Models\Restaurant;
use Inertia\Inertia;
use Inertia\Response;

class PosIntegrationsController extends Controller
{
    /**
     * Status-only view of the restaurant's POS connections. The OAuth
     * connect/callback flow lands here alongside the first adapter (Square).
     */
    public function show(Restaurant $restaurant): Response
    {
        $integrations = $restaurant->posIntegrations()
            ->get()
            ->keyBy(fn (PosIntegration $integration): string => $integration->provider->value);

        // Providers with a built OAuth adapter. Others render as "coming soon".
        $connectable = [PosProviderName::Square, PosProviderName::Clover];

        return Inertia::render('Admin/TenantAdmin/PosIntegrations', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'providers' => collect(PosProviderName::cases())->map(function (PosProviderName $provider) use ($integrations, $restaurant, $connectable): array {
                $available = in_array($provider, $connectable, strict: true);

                // Square and Clover both default to `sandbox` in config/services.php
                // and select their API/OAuth hosts off it. A production deploy
                // that has not set the var connects real owners to the test
                // environment, where real registers never see an order — so the
                // page says so instead of letting it fail silently.
                $environment = $available
                    ? (string) config("services.{$provider->value}.environment", 'sandbox')
                    : null;

                return [
                    'provider' => $provider->value,
                    'label' => $provider->label(),
                    'status' => ($integrations[$provider->value] ?? null)?->status->value
                        ?? PosIntegrationStatus::Disconnected->value,
                    'lastError' => ($integrations[$provider->value] ?? null)?->last_error,
                    'connectedAt' => ($integrations[$provider->value] ?? null)?->created_at?->toIso8601String(),
                    'available' => $available,
                    'environment' => $environment,
                    'sandbox' => $environment !== null && $environment !== 'production',
                    'connectUrl' => $available
                        ? route("admin.restaurant.pos.{$provider->value}.connect", ['restaurant' => $restaurant->subdomain])
                        : null,
                    'disconnectUrl' => $available
                        ? route("admin.restaurant.pos.{$provider->value}.disconnect", ['restaurant' => $restaurant->subdomain])
                        : null,
                ];
            })->values()->all(),
        ]);
    }
}

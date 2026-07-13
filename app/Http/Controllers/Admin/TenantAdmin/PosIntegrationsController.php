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

                return [
                    'provider' => $provider->value,
                    'label' => $provider->label(),
                    'status' => ($integrations[$provider->value] ?? null)?->status->value
                        ?? PosIntegrationStatus::Disconnected->value,
                    'lastError' => ($integrations[$provider->value] ?? null)?->last_error,
                    'connectedAt' => ($integrations[$provider->value] ?? null)?->created_at?->toIso8601String(),
                    'available' => $available,
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

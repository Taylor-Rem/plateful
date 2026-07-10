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

        return Inertia::render('Admin/TenantAdmin/PosIntegrations', [
            'restaurant' => RestaurantData::fromModel($restaurant),
            'providers' => collect(PosProviderName::cases())->map(fn (PosProviderName $provider): array => [
                'provider' => $provider->value,
                'label' => $provider->label(),
                'status' => ($integrations[$provider->value] ?? null)?->status->value
                    ?? PosIntegrationStatus::Disconnected->value,
                'lastError' => ($integrations[$provider->value] ?? null)?->last_error,
                'connectedAt' => ($integrations[$provider->value] ?? null)?->created_at?->toIso8601String(),
                'available' => false,
            ])->values()->all(),
        ]);
    }
}

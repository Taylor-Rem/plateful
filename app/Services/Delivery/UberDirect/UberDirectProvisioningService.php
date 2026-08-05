<?php

namespace App\Services\Delivery\UberDirect;

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryIntegration;
use App\Models\Restaurant;
use App\Services\Delivery\DoorDash\DoorDashProvisioningService;

/**
 * Provisions a restaurant's Uber Direct identity behind the scenes, so the
 * owner enables delivery with one click and pastes nothing — the Uber twin of
 * {@see DoorDashProvisioningService}.
 *
 * Uber's umbrella model nests restaurant sub-organizations under Plateful's
 * root Direct account via the Organizations API: centralized billing (the root
 * account is payer of record) and a silent invite type, so no email ever
 * reaches the restaurant. The returned `organization_id` is the `customer_id`
 * every delivery call is scoped under.
 *
 * One hard asymmetry vs DoorDash: org ids are SERVER-generated and orgs can
 * only be deleted manually by Uber. Idempotency therefore cannot come from
 * deterministic ids + 409-as-success — instead an id we already hold is
 * treated as permanent, and reconnecting reuses it without touching the API.
 */
class UberDirectProvisioningService
{
    public function __construct(
        private UberDirectClient $client,
        private UberDirectTokenService $tokens,
    ) {}

    /**
     * Create (or reuse) this restaurant's sub-organization and persist its id
     * onto the Uber integration row, marking it Connected.
     */
    public function provisionOrganizationFor(Restaurant $restaurant): DeliveryIntegration
    {
        $existing = DeliveryIntegration::withoutTenantScope()
            ->where('restaurant_id', $restaurant->id)
            ->where('provider', DeliveryProviderName::Uber)
            ->first();

        if ($existing !== null && $existing->customer_id !== null) {
            $existing->forceFill([
                'status' => DeliveryIntegrationStatus::Connected,
                'last_error' => null,
            ])->save();

            return $existing;
        }

        $organizationId = $this->createOrganization($restaurant);

        // A platform token only authorizes the orgs that existed when it was
        // minted (verified live: a pre-provisioning token gets 403 for the new
        // org), so the cached tokens must die with this provisioning.
        $this->tokens->forget();

        return DeliveryIntegration::updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'provider' => DeliveryProviderName::Uber,
            ],
            [
                'customer_id' => $organizationId,
                'status' => DeliveryIntegrationStatus::Connected,
                'last_error' => null,
            ],
        );
    }

    private function createOrganization(Restaurant $restaurant): string
    {
        $rootOrganizationId = (string) config('services.uber_direct.customer_id');

        if ($rootOrganizationId === '') {
            throw DeliveryProviderException::notConfigured(DeliveryProviderName::Uber->value);
        }

        $token = $this->tokens->freshAccessToken(UberDirectTokenService::SCOPE_ORGANIZATIONS);

        $response = $this->client->authed($token)->post($this->client->organizationsPath(), [
            'info' => [
                'name' => $this->organizationNameFor($restaurant),
                'billing_type' => 'BILLING_TYPE_CENTRALIZED',
                'merchant_type' => 'MERCHANT_TYPE_RESTAURANT',
                // No contract_type: Uber rejects it under centralized billing
                // and applies CONTRACT_TYPE_PARENT itself (verified live).
            ],
            'hierarchy_info' => [
                'parent_organization_id' => $rootOrganizationId,
            ],
            'options' => [
                'onboarding_invite_type' => 'ONBOARDING_INVITE_TYPE_INVALID',
            ],
        ]);

        $organizationId = $response->json('organization_id');

        if ($response->failed() || ! is_string($organizationId) || $organizationId === '') {
            throw DeliveryProviderException::createFailed(
                DeliveryProviderName::Uber->value,
                "Uber organization provisioning failed (HTTP {$response->status()}): ".$response->body(),
            );
        }

        return $organizationId;
    }

    /**
     * Uber forbids URLs and the characters `/ \ : % < > # =` in org names.
     */
    private function organizationNameFor(Restaurant $restaurant): string
    {
        $name = str_replace(['/', '\\', ':', '%', '<', '>', '#', '='], ' ', (string) $restaurant->name);
        $name = trim((string) preg_replace('/\s+/', ' ', $name));

        return $name !== '' ? $name : 'Plateful Restaurant '.$restaurant->id;
    }
}

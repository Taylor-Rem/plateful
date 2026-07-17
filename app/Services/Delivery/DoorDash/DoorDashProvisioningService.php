<?php

namespace App\Services\Delivery\DoorDash;

use App\Enums\DeliveryIntegrationStatus;
use App\Enums\DeliveryProviderName;
use App\Exceptions\DeliveryProviderException;
use App\Models\DeliveryIntegration;
use App\Models\Restaurant;
use App\Services\Stripe\StripeConnectService;
use Illuminate\Http\Client\Response;

/**
 * Provisions a restaurant's DoorDash Drive identity behind the scenes, so the
 * owner enables delivery with one click and pastes nothing.
 *
 * DoorDash's umbrella model keys every delivery on a Business + Store that
 * Plateful owns under its single platform account. We mint deterministic
 * external ids (`pf-biz-{id}` / `pf-store-{id}`) and register them via the
 * Developer API; from then on quotes carry those ids as
 * `pickup_external_business_id` / `pickup_external_store_id`. This mirrors
 * {@see StripeConnectService::createExpressAccount()} —
 * provision a provider-side record for the restaurant, then persist its ids.
 *
 * The ids are plain identifiers, not secrets, so they live unencrypted on the
 * integration row; the JWT credentials that authenticate every call stay in
 * config. Re-provisioning after a disconnect is idempotent: the external ids are
 * deterministic and DoorDash returns 409 for an id it already knows, which we
 * treat as success.
 */
class DoorDashProvisioningService
{
    public function __construct(private DoorDashClient $client) {}

    /**
     * Register (or re-register) this restaurant's Business + Store and persist
     * the ids onto its DoorDash integration, marking it Connected.
     */
    public function provisionStoreFor(Restaurant $restaurant): DeliveryIntegration
    {
        $businessId = $this->businessIdFor($restaurant);
        $storeId = $this->storeIdFor($restaurant);

        $this->createBusiness($businessId, $restaurant);
        $this->createStore($businessId, $storeId, $restaurant);

        return DeliveryIntegration::updateOrCreate(
            [
                'restaurant_id' => $restaurant->id,
                'provider' => DeliveryProviderName::DoorDash,
            ],
            [
                'external_business_id' => $businessId,
                'external_store_id' => $storeId,
                'status' => DeliveryIntegrationStatus::Connected,
                'last_error' => null,
            ],
        );
    }

    public function businessIdFor(Restaurant $restaurant): string
    {
        return 'pf-biz-'.$restaurant->id;
    }

    public function storeIdFor(Restaurant $restaurant): string
    {
        return 'pf-store-'.$restaurant->id;
    }

    private function createBusiness(string $businessId, Restaurant $restaurant): void
    {
        $response = $this->client->authed()->post($this->client->developerPath('/businesses'), [
            'external_business_id' => $businessId,
            'name' => $restaurant->name,
        ]);

        $this->assertProvisioned('business', $response);
    }

    private function createStore(string $businessId, string $storeId, Restaurant $restaurant): void
    {
        $response = $this->client->authed()->post(
            $this->client->developerPath('/businesses/'.$businessId.'/stores'),
            [
                'external_store_id' => $storeId,
                'name' => $restaurant->name,
                // DoorDash geocodes the single-line address — no lat/lng, same as
                // the quote path. See DoorDashAddress.
                'address' => DoorDashAddress::fromRestaurant($restaurant),
                'phone_number' => $this->phoneFor($restaurant),
            ],
        );

        $this->assertProvisioned('store', $response);
    }

    /**
     * A 409 means the Business/Store already exists — which is exactly the state
     * we want after a re-enable, so it is success, not failure.
     */
    private function assertProvisioned(string $what, Response $response): void
    {
        if ($response->successful() || $response->status() === 409) {
            return;
        }

        throw DeliveryProviderException::createFailed(
            'doordash',
            "DoorDash {$what} provisioning failed (HTTP {$response->status()}): ".$response->body(),
        );
    }

    /**
     * Best-effort E.164 for the stored (unformatted) restaurant phone.
     */
    private function phoneFor(Restaurant $restaurant): string
    {
        $digits = preg_replace('/\D+/', '', (string) $restaurant->phone) ?? '';

        if (strlen($digits) === 10) {
            return '+1'.$digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+'.$digits;
        }

        return $digits === '' ? '' : '+'.$digits;
    }
}

<?php

use App\Data\AddressData;
use App\Data\AuthSessionData;
use App\Data\CartData;
use App\Data\CartItemData;
use App\Data\CheckoutIntentData;
use App\Data\DeliveryAssignmentData;
use App\Data\ItemTemplateGroupData;
use App\Data\ItemTemplateOptionData;
use App\Data\MeData;
use App\Data\MenuCategoryData;
use App\Data\MenuItemData;
use App\Data\MenuItemIngredientData;
use App\Data\OrderData;
use App\Data\OrderItemData;
use App\Data\OrderPlacedData;
use App\Data\PaginationMetaData;
use App\Data\RestaurantData;
use App\Data\RestaurantSummaryData;
use App\Data\TwoFactorChallengeData;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The v1 API contract. These DTOs (and their generated TypeScript) are what
 * the mobile app compiles against, so their shape is pinned: from v1 ship,
 * changes are additive only. A failing snapshot here means either a breaking
 * change that belongs behind /v2, or a deliberate additive change — update
 * the snapshot with `--update-snapshots` only in the second case.
 */
const API_V1_CONTRACT = [
    MeData::class,
    AuthSessionData::class,
    TwoFactorChallengeData::class,
    PaginationMetaData::class,
    RestaurantSummaryData::class,
    RestaurantData::class,
    MenuCategoryData::class,
    MenuItemData::class,
    MenuItemIngredientData::class,
    ItemTemplateGroupData::class,
    ItemTemplateOptionData::class,
    CartData::class,
    CartItemData::class,
    CheckoutIntentData::class,
    OrderPlacedData::class,
    OrderData::class,
    OrderItemData::class,
    DeliveryAssignmentData::class,
    AddressData::class,
];

/**
 * @return array<string, array<string, string>>
 */
function apiV1ContractShape(): array
{
    $shape = [];

    foreach (API_V1_CONTRACT as $class) {
        $constructor = new ReflectionClass($class)->getConstructor();
        $fields = [];

        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            $fields[$parameter->getName()] = (string) $parameter->getType();
        }

        $shape[$class] = $fields;
    }

    return $shape;
}

test('the v1 response DTOs match the pinned contract', function () {
    expect(json_encode(apiV1ContractShape(), JSON_PRETTY_PRINT))->toMatchSnapshot();
});

test('every v1 contract DTO is exported to TypeScript', function () {
    foreach (API_V1_CONTRACT as $class) {
        expect(new ReflectionClass($class)->getAttributes(TypeScript::class))
            ->not->toBeEmpty("{$class} is missing #[TypeScript]");
    }
});

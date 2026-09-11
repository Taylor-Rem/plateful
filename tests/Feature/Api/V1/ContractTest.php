<?php

use App\Data\AuthSessionData;
use App\Data\MeData;
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

<?php

declare(strict_types=1);

namespace App\Finance\Mapping;

use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;

/**
 * Maps the engine's {@see AssumptionSet} DTO to and from storage. Unlike the
 * household and scenario payloads this is NOT personal data, so it is stored as a
 * plain JSON column; $name, $sourceNote and $isDefault are kept as clear columns
 * for listing and for the admin to pick the default.
 *
 * The correlation matrix is forced back to float on hydrate: a JSON 1.0 can decode
 * as int 1, and the Cholesky decomposition in the Monte Carlo needs floats.
 */
final class AssumptionSetMapper
{
    /**
     * The whole set as a self-contained array (figures plus name/source/default),
     * for the frozen snapshot stored on a simulation run so results survive later
     * edits to the live set.
     */
    public static function toArray(AssumptionSet $set): array
    {
        return [
            'name' => $set->name,
            'sourceNote' => $set->sourceNote,
            'isDefault' => $set->isDefault,
            ...self::payload($set),
        ];
    }

    public static function fromArray(array $data): AssumptionSet
    {
        return self::hydrate($data['name'], $data['sourceNote'], $data['isDefault'], $data);
    }

    /** The economic figures (everything except the clear name/source/default columns). */
    public static function payload(AssumptionSet $set): array
    {
        return [
            'assetClasses' => array_map(
                static fn (AssetClassAssumption $a): array => [
                    'name' => $a->name,
                    'expectedRealReturn' => Codec::bps($a->expectedRealReturn),
                    'volatility' => Codec::bps($a->volatility),
                ],
                $set->assetClasses,
            ),
            'correlationMatrix' => $set->correlationMatrix,
            'inflationMean' => Codec::bps($set->inflationMean),
            'inflationVolatility' => Codec::bps($set->inflationVolatility),
            'houseGrowth' => Codec::bps($set->houseGrowth),
            'rentInflation' => Codec::bps($set->rentInflation),
            'salaryGrowth' => Codec::bps($set->salaryGrowth),
            'investmentIncomeYield' => Codec::bps($set->investmentIncomeYield),
        ];
    }

    public static function hydrate(string $name, string $sourceNote, bool $isDefault, array $payload): AssumptionSet
    {
        return new AssumptionSet(
            name: $name,
            sourceNote: $sourceNote,
            assetClasses: array_map(
                static fn (array $a): AssetClassAssumption => new AssetClassAssumption(
                    name: $a['name'],
                    expectedRealReturn: Codec::percent($a['expectedRealReturn']),
                    volatility: Codec::percent($a['volatility']),
                ),
                $payload['assetClasses'],
            ),
            correlationMatrix: array_map(
                static fn (array $row): array => array_map(static fn ($v): float => (float) $v, $row),
                $payload['correlationMatrix'],
            ),
            inflationMean: Codec::percent($payload['inflationMean']),
            inflationVolatility: Codec::percent($payload['inflationVolatility']),
            houseGrowth: Codec::percent($payload['houseGrowth']),
            rentInflation: Codec::percent($payload['rentInflation']),
            salaryGrowth: Codec::percent($payload['salaryGrowth']),
            // Back-compat: a pre-A5 snapshot has no income yield; default to 2.0% (200 bps).
            investmentIncomeYield: Codec::percent($payload['investmentIncomeYield'] ?? 200),
            isDefault: $isDefault,
        );
    }
}

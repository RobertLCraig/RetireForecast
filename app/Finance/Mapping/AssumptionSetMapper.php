<?php

declare(strict_types=1);

namespace App\Finance\Mapping;

use RetireForecast\FinanceEngine\Dto\AssetClassAssumption;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;
use RetireForecast\FinanceEngine\Dto\FigureSource;

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
                    // Where each figure came from and when it was last checked (board card 0062).
                    // Stored with the figures, so a frozen snapshot keeps the citation the run was
                    // made on rather than picking up a later re-source.
                    'returnSource' => $a->returnSource,
                    'returnVerifiedOn' => $a->returnVerifiedOn,
                    'volatilitySource' => $a->volatilitySource,
                    'volatilityVerifiedOn' => $a->volatilityVerifiedOn,
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
            // Null = deterministic house growth (not stochastic); preserved through the round-trip.
            'houseGrowthVolatility' => $set->houseGrowthVolatility !== null ? Codec::bps($set->houseGrowthVolatility) : null,
            'houseEquityCorrelation' => $set->houseEquityCorrelation,
            // Null = deterministic salary growth; preserved through the round-trip (same contract as house).
            'salaryGrowthVolatility' => $set->salaryGrowthVolatility !== null ? Codec::bps($set->salaryGrowthVolatility) : null,
            'salaryEquityCorrelation' => $set->salaryEquityCorrelation,
            // Null = flat-real care fees (no above-CPI escalation); preserved through the round-trip.
            'careCostRealGrowth' => $set->careCostRealGrowth !== null ? Codec::bps($set->careCostRealGrowth) : null,
            // Null = returns are gross of charges (no ongoing charge modelled); preserved through
            // the round-trip, so a run stored before charges existed reproduces byte-identically.
            'investmentCharge' => $set->investmentCharge !== null ? Codec::bps($set->investmentCharge) : null,
            // Null = derive it from the index volatility; the RAW field is stored, not the derived
            // figure, so a re-sourced index still moves a set the reader never overrode.
            'singlePropertyVolatility' => $set->singlePropertyVolatility !== null ? Codec::bps($set->singlePropertyVolatility) : null,
            // Board card 0064. 0.0 / null = the memoryless, independent inflation draw, which is
            // what a snapshot stored before the card reproduces: the RAW fields are stored, not the
            // clamped ones, so a re-sourced figure moves a set the reader never overrode.
            'inflationPersistence' => $set->inflationPersistence,
            'inflationAssetCorrelations' => $set->inflationAssetCorrelations,
            // Board card 0065. The per-figure sourcing rides the snapshot so a stored run stays
            // auditable back to where each of its economic figures came from and when that was last
            // checked. Without it a run kept its figures and lost their provenance, which is the
            // half that decides whether the figures can still be relied on.
            'economicSourcing' => array_map(
                static fn (FigureSource $s): array => [
                    'figure' => $s->figure,
                    'label' => $s->label,
                    'source' => $s->source,
                    'verifiedOn' => $s->verifiedOn,
                ],
                $set->economicSourcing,
            ),
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
                    // Back-compat: a snapshot stored before board card 0062 carries no sourcing,
                    // which reads as "not stated" rather than inventing a citation for it.
                    returnSource: $a['returnSource'] ?? null,
                    returnVerifiedOn: $a['returnVerifiedOn'] ?? null,
                    volatilitySource: $a['volatilitySource'] ?? null,
                    volatilityVerifiedOn: $a['volatilityVerifiedOn'] ?? null,
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
            // Back-compat: a pre-2026-07-18 snapshot has no house volatility; null keeps its
            // house growth deterministic, so an old stored run reproduces exactly as before.
            houseGrowthVolatility: isset($payload['houseGrowthVolatility']) ? Codec::percent($payload['houseGrowthVolatility']) : null,
            houseEquityCorrelation: (float) ($payload['houseEquityCorrelation'] ?? 0.2),
            // Back-compat: a pre-2026-07-18 snapshot has no salary volatility; null keeps its
            // salary growth deterministic, so an old stored run reproduces exactly as before.
            salaryGrowthVolatility: isset($payload['salaryGrowthVolatility']) ? Codec::percent($payload['salaryGrowthVolatility']) : null,
            salaryEquityCorrelation: (float) ($payload['salaryEquityCorrelation'] ?? 0.1),
            // Back-compat: a pre-A1 snapshot has no care escalation; null keeps care fees flat-real,
            // so an old stored care run reproduces its byte-identical result.
            careCostRealGrowth: isset($payload['careCostRealGrowth']) ? Codec::percent($payload['careCostRealGrowth']) : null,
            // Back-compat: a snapshot stored before 2026-07-31 has no charge figure; null keeps its
            // returns gross of charges, so an old stored run reproduces its byte-identical result.
            investmentCharge: isset($payload['investmentCharge']) ? Codec::percent($payload['investmentCharge']) : null,
            // A snapshot stored before card 0029 carries no figure, so it derives the widened one,
            // exactly as a fresh set does. Its stored RESULT is not comparable across that change,
            // which is what the ENGINE_VERSION bump records.
            singlePropertyVolatility: isset($payload['singlePropertyVolatility']) ? Codec::percent($payload['singlePropertyVolatility']) : null,
            // Back-compat: a snapshot stored before board card 0064 states neither, which is the
            // memoryless independent draw, so an old stored run reproduces byte-identically.
            // Forced back to float, as the correlation matrix above is, because a JSON -0.5 can
            // decode as a string and the Cholesky decomposition needs floats.
            inflationPersistence: (float) ($payload['inflationPersistence'] ?? 0.0),
            inflationAssetCorrelations: isset($payload['inflationAssetCorrelations'])
                ? array_map(static fn ($v): float => (float) $v, $payload['inflationAssetCorrelations'])
                : null,
            isDefault: $isDefault,
            // Back-compat: a snapshot stored before board card 0065 carries no sourcing, which
            // reads as "not stated" rather than inventing a citation for it, exactly as the
            // per-asset-class sourcing above does.
            economicSourcing: array_map(
                static fn (array $s): FigureSource => new FigureSource(
                    figure: (string) $s['figure'],
                    label: (string) $s['label'],
                    source: (string) $s['source'],
                    verifiedOn: (string) $s['verifiedOn'],
                ),
                $payload['economicSourcing'] ?? [],
            ),
        );
    }
}

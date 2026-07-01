<?php

declare(strict_types=1);

namespace App\Finance\Mapping;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\MonteCarlo\CareImpact;
use RetireForecast\FinanceEngine\MonteCarlo\LongevityDistribution;
use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;

/**
 * Maps the engine's {@see SimulationResult} (a Monte Carlo aggregate) to and from
 * the array stored as a Result's encrypted payload. Money figures (terminal-wealth
 * percentiles and the fan-chart bands) are stored as integer pence; probabilities
 * and the depletion rate are floats, cast back to float on hydrate so a JSON 1.0
 * that decodes as int 1 does not change type.
 */
final class SimulationResultMapper
{
    private const PERCENTILES = ['p10', 'p25', 'p50', 'p75', 'p90'];

    public static function toArray(SimulationResult $result): array
    {
        return [
            'nPaths' => $result->nPaths,
            'seed' => $result->seed,
            'successProbabilityEssentials' => $result->successProbabilityEssentials,
            'successProbabilityFullSpend' => $result->successProbabilityFullSpend,
            'depletionRate' => $result->depletionRate,
            'medianDepletionYear' => $result->medianDepletionYear,
            'terminalWealthPercentiles' => self::penceBands($result->terminalWealthPercentiles),
            'usableWealthPercentiles' => $result->usableWealthPercentiles === []
                ? []
                : self::penceBands($result->usableWealthPercentiles),
            'fanChart' => self::penceFan($result->fanChart),
            'usableFanChart' => self::penceFan($result->usableFanChart),
            'longevity' => self::longevityToArray($result->longevity),
            'careImpact' => self::careImpactToArray($result->careImpact),
        ];
    }

    public static function fromArray(array $data): SimulationResult
    {
        return new SimulationResult(
            nPaths: $data['nPaths'],
            seed: $data['seed'],
            successProbabilityEssentials: (float) $data['successProbabilityEssentials'],
            successProbabilityFullSpend: (float) $data['successProbabilityFullSpend'],
            depletionRate: (float) $data['depletionRate'],
            medianDepletionYear: $data['medianDepletionYear'],
            terminalWealthPercentiles: self::moneyBands($data['terminalWealthPercentiles']),
            usableWealthPercentiles: empty($data['usableWealthPercentiles'])
                ? []
                : self::moneyBands($data['usableWealthPercentiles']),
            fanChart: self::moneyFan($data['fanChart']),
            // Runs persisted before the per-year usable fan landed have no key — default to empty.
            usableFanChart: self::moneyFan($data['usableFanChart'] ?? []),
            // Runs persisted before the longevity distribution landed have no key — default to null.
            longevity: self::longevityFromArray($data['longevity'] ?? null),
            // Runs persisted before care-cost modelling (or with it off) have no key — default to null.
            careImpact: self::careImpactFromArray($data['careImpact'] ?? null),
        );
    }

    /** @return array<string, int|float>|null */
    private static function careImpactToArray(?CareImpact $c): ?array
    {
        return $c === null ? null : [
            'shareOfPathsWithCare' => $c->shareOfPathsWithCare,
            'medianCareCost' => Codec::pence($c->medianCareCost),
            'p90CareCost' => Codec::pence($c->p90CareCost),
        ];
    }

    /** @param  array<string, mixed>|null  $data */
    private static function careImpactFromArray(?array $data): ?CareImpact
    {
        return $data === null ? null : new CareImpact(
            shareOfPathsWithCare: (float) $data['shareOfPathsWithCare'],
            medianCareCost: Codec::money($data['medianCareCost']),
            p90CareCost: Codec::money($data['p90CareCost']),
        );
    }

    /** @return array<string, int|float>|null */
    private static function longevityToArray(?LongevityDistribution $l): ?array
    {
        return $l === null ? null : [
            'lastSurvivorAgeP10' => $l->lastSurvivorAgeP10,
            'lastSurvivorAgeP50' => $l->lastSurvivorAgeP50,
            'lastSurvivorAgeP90' => $l->lastSurvivorAgeP90,
            'planYearsP50' => $l->planYearsP50,
            'planYearsP90' => $l->planYearsP90,
            'reaches95' => $l->reaches95,
            'reaches100' => $l->reaches100,
        ];
    }

    /** @param  array<string, mixed>|null  $data */
    private static function longevityFromArray(?array $data): ?LongevityDistribution
    {
        return $data === null ? null : new LongevityDistribution(
            lastSurvivorAgeP10: (int) $data['lastSurvivorAgeP10'],
            lastSurvivorAgeP50: (int) $data['lastSurvivorAgeP50'],
            lastSurvivorAgeP90: (int) $data['lastSurvivorAgeP90'],
            planYearsP50: (int) $data['planYearsP50'],
            planYearsP90: (int) $data['planYearsP90'],
            reaches95: (float) $data['reaches95'],
            reaches100: (float) $data['reaches100'],
        );
    }

    /**
     * A per-year fan (list of bands) Money -> pence. Shared by the total and usable fans.
     *
     * @param  list<array<string, mixed>>  $fan
     * @return list<array<string, int>>
     */
    private static function penceFan(array $fan): array
    {
        return array_map(static fn (array $band): array => [
            'calendarYear' => $band['calendarYear'],
            'paths' => $band['paths'],
            ...self::penceBands($band),
        ], $fan);
    }

    /**
     * A per-year fan (list of bands) pence -> Money. Shared by the total and usable fans.
     *
     * @param  list<array<string, mixed>>  $fan
     * @return list<array<string, mixed>>
     */
    private static function moneyFan(array $fan): array
    {
        return array_map(static fn (array $band): array => [
            'calendarYear' => $band['calendarYear'],
            'paths' => $band['paths'],
            ...self::moneyBands($band),
        ], $fan);
    }

    /**
     * @param  array<string, mixed>  $bands  holds Money under each percentile key
     * @return array<string, int>
     */
    private static function penceBands(array $bands): array
    {
        $out = [];
        foreach (self::PERCENTILES as $p) {
            $out[$p] = Codec::pence($bands[$p]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $bands  holds pence under each percentile key
     * @return array<string, Money>
     */
    private static function moneyBands(array $bands): array
    {
        $out = [];
        foreach (self::PERCENTILES as $p) {
            $out[$p] = Codec::money($bands[$p]);
        }

        return $out;
    }
}

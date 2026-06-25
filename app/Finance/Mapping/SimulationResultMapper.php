<?php

declare(strict_types=1);

namespace App\Finance\Mapping;

use RetireForecast\FinanceEngine\Money\Money;
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
            'fanChart' => array_map(
                static fn (array $band): array => [
                    'calendarYear' => $band['calendarYear'],
                    'paths' => $band['paths'],
                    ...self::penceBands($band),
                ],
                $result->fanChart,
            ),
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
            fanChart: array_map(
                static fn (array $band): array => [
                    'calendarYear' => $band['calendarYear'],
                    'paths' => $band['paths'],
                    ...self::moneyBands($band),
                ],
                $data['fanChart'],
            ),
        );
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

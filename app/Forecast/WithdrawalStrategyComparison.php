<?php

declare(strict_types=1);

namespace App\Forecast;

use App\Compliance\Interpretation;
use App\Models\Scenario;
use RetireForecast\FinanceEngine\Forecast\DrawdownStrategy;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\YearResult;

/**
 * Prices the household's withdrawal (drawdown) sequencing: the total tax paid across the
 * whole plan under the current strategy (tax-efficient: spend non-pension assets first) vs
 * the "fill the bands" strategy, on the SAME household + assumptions + deterministic basis,
 * so the reader can see what re-ordering the draw is worth over a lifetime.
 *
 * Neutral by construction: it reports two figures and their difference, nothing more. The
 * directive "lean towards X" steer lives only in the walled-off {@see Interpretation}
 * (the guidance-only partition). Each figure is the engine's own summed {@see YearResult::$totalTax},
 * never a re-derivation (the displayed-figure provenance rule), and the saving is exactly the
 * difference of the two (one figure, one home).
 */
final class WithdrawalStrategyComparison
{
    private function __construct(
        public readonly int $baselineTaxPence,
        public readonly int $fillBandsTaxPence,
        public readonly int $savingPence, // baselineTax - fillBandsTax; positive = fill-the-bands pays less
    ) {}

    public static function for(ScenarioForecaster $forecaster, Scenario $scenario): self
    {
        $baseline = self::lifetimeTax($forecaster->deterministicUnderStrategy($scenario, DrawdownStrategy::TaxEfficient));
        $fillBands = self::lifetimeTax($forecaster->deterministicUnderStrategy($scenario, DrawdownStrategy::FillBands));

        return new self($baseline, $fillBands, $baseline - $fillBands);
    }

    /** Total tax paid across every year of the projection. */
    private static function lifetimeTax(ForecastResult $forecast): int
    {
        $total = 0;
        foreach ($forecast->years as $year) {
            $total += $year->totalTax->pence;
        }

        return $total;
    }

    /** True when "fill the bands" pays strictly less lifetime tax than the current strategy. */
    public function fillBandsSaves(): bool
    {
        return $this->savingPence > 0;
    }
}

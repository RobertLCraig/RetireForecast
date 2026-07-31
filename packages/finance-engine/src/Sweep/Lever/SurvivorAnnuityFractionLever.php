<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep\Lever;

use RetireForecast\FinanceEngine\Dto\DcPension;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * Shoring up the survivor, annuity edition: set the survivor's fraction on a joint-life annuity
 * purchase to the swept value (a percentage, 0–100). Like the DB survivor lever, a larger fraction
 * carries more guaranteed income across the survivor cliff, so success is monotone increasing, and
 * the change touches neither mortality nor the return path, so common random numbers stay valid.
 *
 * It varies only annuities that are ALREADY joint-life (a non-null survivor fraction). A single-life
 * annuity is priced on a single-life quote — turning it joint-life at the same rate would model
 * survivor income the quote never paid for — so those are left untouched, and non-annuitised pots
 * pass through unchanged. This holds the annuitant's own income fixed and asks only "how much of it
 * should carry on to the survivor".
 */
final class SurvivorAnnuityFractionLever implements SweepLever
{
    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $fraction = Percent::fromPercent(max(0.0, min(100.0, $value)));

        $pensions = array_map(
            static function ($pension) use ($fraction) {
                if (! $pension instanceof DcPension
                    || $pension->annuityPurchase === null
                    || $pension->annuityPurchase->survivorFraction === null) {
                    return $pension;
                }

                return $pension->withAnnuityPurchase($pension->annuityPurchase->withSurvivorFraction($fraction));
            },
            $household->pensions,
        );

        return new SweepInputs(
            $household->withPensions($pensions),
            $settings,
        );
    }

    public function name(): string
    {
        return 'annuity survivor income';
    }

    public function unit(): string
    {
        return '%';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Increasing;
    }
}

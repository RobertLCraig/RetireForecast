<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep\Lever;

use RetireForecast\FinanceEngine\Dto\DbPension;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * Shoring up the survivor: set the survivor's fraction on the household's Defined Benefit scheme(s)
 * to the swept value (a percentage, 0–100). This is a survivor-first lever — the binding risk in a
 * couple is survivor-poverty after the first death, and a bigger spouse's pension is guaranteed
 * income that carries through the cliff. A larger fraction can only raise the chance the money lasts
 * (never lower it), so success is monotone increasing, and the change touches neither mortality nor
 * the return path, so common random numbers stay valid.
 *
 * It varies only schemes that ALREADY provide a survivor's pension (a non-null fraction): sweeping a
 * scheme that offers none would invent a benefit that does not exist. Non-DB pensions and
 * fraction-less DB schemes pass through unchanged.
 */
final class SurvivorDbFractionLever implements SweepLever
{
    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        $fraction = Percent::fromPercent(max(0.0, min(100.0, $value)));

        $pensions = array_map(
            static fn ($pension) => $pension instanceof DbPension && $pension->spousePensionFraction !== null
                ? $pension->withSpousePensionFraction($fraction)
                : $pension,
            $household->pensions,
        );

        return new SweepInputs(
            $household->withPensions($pensions),
            $settings,
        );
    }

    public function name(): string
    {
        return 'survivor pension';
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

<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep\Lever;

use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Sweep\LeverDirection;
use RetireForecast\FinanceEngine\Sweep\SweepInputs;
use RetireForecast\FinanceEngine\Sweep\SweepLever;

/**
 * Whether late-life care fees are in the picture: a BINARY toggle of {@see ForecastSettings::modelCareCost}
 * across a two-point grid — 0 = care not modelled, 1 = care modelled. Care is an off-by-default,
 * six-figure fat tail; left out it silently flatters every other threshold, so this lever exists to
 * show how much of the odds that tail actually moves.
 *
 * This is the first lever that flips a **setting**, not the household — care is a `ForecastSettings`
 * flag, not a household attribute, so `apply()` returns the household untouched and the settings
 * toggled. It is deliberately NOT a sweep. Turning care on inserts extra random draws (a per-person
 * Bernoulli, plus duration and type on a hit) *before* the investment-return path is drawn, so on the
 * same seed the two states' return streams **desync**: care-off and care-on are two independent Monte
 * Carlo samples, NOT a common-random-numbers like-for-like pair. So success is not monotone-fittable
 * in the toggle ({@see LeverDirection::Unknown}); the two points are read as a **pinned before/after**
 * (each with its own confidence interval), never interpolated into a fractional "limit" — there is no
 * such thing as 63% of care being modelled. The value threshold is 0.5 so any grid either side reads
 * cleanly as off/on, and both states are set explicitly regardless of the scenario's own care setting,
 * so the readout is always a clean off-vs-on.
 */
final class CareModellingLever implements SweepLever
{
    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs
    {
        return new SweepInputs($household, $settings->withModelCareCost($value >= 0.5));
    }

    public function name(): string
    {
        return 'whether care fees are modelled';
    }

    public function unit(): string
    {
        return 'off/on';
    }

    public function direction(): LeverDirection
    {
        return LeverDirection::Unknown;
    }
}

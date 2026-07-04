<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;

/**
 * One thing the household can vary to change whether the money lasts — a buy price, a
 * retirement age, a spend level, a family contribution. A lever maps a numeric value to the
 * household + settings to simulate at that value, so the {@see SweepEngine} can sweep a grid
 * of values and measure the success probability at each.
 *
 * $direction says whether success is provably monotone in the value, which the engine uses to
 * decide whether a monotone crossing fit is valid (S3). Phase 0 ships this contract + the
 * sweep machinery; the concrete real-world levers (buy price, retirement age, …) are a later
 * phase, and tests provide their own synthetic levers to exercise the spine.
 */
interface SweepLever
{
    /** The household + settings to simulate when this lever is set to $value. */
    public function apply(Household $household, ForecastSettings $settings, float $value): SweepInputs;

    /** A short human name for the lever (e.g. "buy price", "retirement age"). */
    public function name(): string;

    /** The unit a value is expressed in (e.g. "£", "years"), for labelling a readout. */
    public function unit(): string;

    /** Whether success is provably monotone in the value, and which way. */
    public function direction(): LeverDirection;
}

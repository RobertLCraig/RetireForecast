<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Sweep;

use RetireForecast\FinanceEngine\MonteCarlo\SimulationResult;
use RetireForecast\FinanceEngine\MonteCarlo\Simulator;

/**
 * What "success" a sweep measures — the bar the money must clear across the Monte Carlo's
 * futures. Parameterised (not hard-coded) so a sweep can produce an essentials-last curve
 * AND a full-spend-last curve (Rob, 2026-07-04), and the caller picks the target probability.
 *
 *  - Essentials: essential spending is covered every year (the money "lasts" in the strict sense).
 *  - FullSpend:  essential + discretionary spending is covered every year (the fuller ask).
 *
 * Both read a probability the {@see Simulator} already
 * computes, so a sweep point is one ordinary simulation run.
 */
enum SweepMetric: string
{
    case Essentials = 'essentials';
    case FullSpend = 'full_spend';

    /** The success probability for this metric on a completed run. */
    public function probability(SimulationResult $result): float
    {
        return match ($this) {
            self::Essentials => $result->successProbabilityEssentials,
            self::FullSpend => $result->successProbabilityFullSpend,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Essentials => 'essential spending lasts',
            self::FullSpend => 'full spending lasts',
        };
    }
}

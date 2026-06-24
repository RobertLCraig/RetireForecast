<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Dto\AssumptionSet;

/**
 * How invested pots (DC pensions, ISA, GIA) are split across the asset classes of
 * an AssumptionSet. Weights line up with {@see AssumptionSet::$assetClasses} order
 * (global equities, gilts/bonds, cash) and must sum to 1.
 *
 * The default is the signed-off "cautious 40/60" (40% equities, 60% bonds, no cash
 * within pots); cash accounts are modelled separately at the cash assumption.
 */
final class PortfolioAllocation
{
    /**
     * @param  list<float>  $weights  same order as the AssumptionSet's asset classes
     */
    public function __construct(public readonly array $weights) {}

    public static function cautious40_60(): self
    {
        return new self([0.40, 0.60, 0.0]);
    }

    /** The allocation-weighted expected real return for invested pots. */
    public function blendedRealReturn(AssumptionSet $set): float
    {
        $blended = 0.0;
        foreach ($set->assetClasses as $i => $assetClass) {
            $blended += ($this->weights[$i] ?? 0.0) * $assetClass->expectedRealReturn->asFraction();
        }

        return $blended;
    }
}

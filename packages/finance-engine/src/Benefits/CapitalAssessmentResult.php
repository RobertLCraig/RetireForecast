<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\Warning;

/**
 * How a household's assessable capital affects pension-age means-tested benefits:
 * the deemed "tariff" income it generates, and whether Housing Benefit / Council
 * Tax Support are lost by crossing the £16,000 capital limit.
 *
 * Pension Credit itself has no upper capital limit, so $tariffIncomeWeekly always
 * applies; $housingSupportEligible is the cliff that can vanish overnight when a
 * home sale converts an exempt asset into assessable capital.
 */
final class CapitalAssessmentResult
{
    /**
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public readonly Money $assessableCapital,
        public readonly Money $tariffIncomeWeekly,
        public readonly Money $tariffIncomeAnnual,
        public readonly bool $housingSupportEligible,
        public readonly Money $housingSupportUpperCapitalLimit,
        public readonly array $warnings,
    ) {}
}

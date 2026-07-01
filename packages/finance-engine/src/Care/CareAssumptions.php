<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Care;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * Sourced assumptions for the stochastic late-life care-cost risk modelled in the Monte Carlo:
 * how likely a person is to need residential/nursing care, how long it typically lasts, and the
 * self-funder weekly fee. Every figure is a modelling assumption carrying its source; the numbers
 * are deliberately conservative and clearly flagged, because care is a fat right tail (most pay
 * nothing, a minority face very large bills), so a single "expected" figure would mislead — the
 * point is to show that tail in the distribution.
 *
 * Sources (verified_on 2026-07-01):
 *  - probabilityOfCare ~1 in 4: the Dilnot Commission / PSSRU estimate that around a quarter of
 *    people aged 65 will need residential or nursing care in later life. A single household-level
 *    probability per person (v1); a sex/age-differentiated rate is a flagged refinement.
 *  - duration (mean ~2.5 yr, right-skewed): PSSRU/LSE "Length of stay in care homes" (dp2769) —
 *    median stay ~19.6 months, mean ~29.7 months, with 72% having died within 42 months; modelled
 *    as an exponential with this mean, floored at 1 year and capped, on an annual grid.
 *  - weekly fees (self-funder, LaingBuisson "Care of Older People" / Care Homes for Older People
 *    UK Market Report, 35th ed., 2025): residential ~£1,300/wk, nursing ~£1,600/wk. Regional
 *    variation (London/SE +20-35%) is not modelled.
 *
 * v1 simplifications (flagged): the modelled care spell is the final $duration years of life
 * (care need concentrates near death — the engine's cohort deathAge drives timing, rather than
 * ONS health-state life expectancy, a refinement); and the GROSS self-funder cost is charged —
 * once assets fall below the means-test threshold a local authority contributes, which is NOT
 * modelled, so the tail is conservative. See {@see CareMeansTest}.
 */
final class CareAssumptions
{
    public const WEEKS_PER_YEAR = 52;

    public function __construct(
        public readonly float $probabilityOfCare,
        public readonly float $meanDurationYears,
        public readonly int $maxDurationYears,
        public readonly float $probabilityNursing,
        public readonly Money $residentialWeekly,
        public readonly Money $nursingWeekly,
    ) {}

    public static function default(): self
    {
        return new self(
            probabilityOfCare: 0.25,
            meanDurationYears: 2.5,
            maxDurationYears: 8,
            probabilityNursing: 0.35,
            residentialWeekly: Money::fromPounds(1_300),
            nursingWeekly: Money::fromPounds(1_600),
        );
    }

    public function residentialAnnual(): Money
    {
        return Money::fromPence($this->residentialWeekly->pence * self::WEEKS_PER_YEAR);
    }

    public function nursingAnnual(): Money
    {
        return Money::fromPence($this->nursingWeekly->pence * self::WEEKS_PER_YEAR);
    }
}

<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\Warning;

/**
 * One year of a projection, with money figures expressed in REAL terms (today's
 * money) so a 30-year series is directly comparable. The projector works nominally
 * internally (to capture fiscal drag against frozen tax thresholds) and deflates to
 * these real figures.
 *
 * $unmetSpend is the part of the target spend that could not be funded because
 * assets were exhausted — the first year it is positive is when the money runs out.
 *
 * $essentialSpend is the essential floor within $spendTarget (rent or property running
 * costs included, survivor factor applied) — the bar the "essentials always met" measure
 * is judged against, and the figure the income-floor readout compares secure income to.
 *
 * $incomeBySource breaks the year's inflows into the canonical {@see INCOME_SOURCES}
 * (real money) so the drill-down cashflow ladder can show where money came from and
 * how any shortfall was funded. Every source that should reach spendable cash appears
 * here, which is the visual guard against silently dropping one (e.g. tax-free DLA).
 */
final class YearResult
{
    /**
     * The canonical income-source keys, in display order: earned salary; defined
     * benefit; State Pension; other taxable income (annuity, rental); tax-free
     * income (e.g. DLA); pension tax-free lump sums; taxable pension drawdown
     * (planned + drawn to meet a shortfall); and capital drawn from savings/ISA/GIA.
     */
    public const INCOME_SOURCES = [
        'salary',
        'defined_benefit',
        'state_pension',
        'other_taxable',
        'tax_free_income',
        'pension_lump_sum',
        'pension_drawdown',
        'asset_drawdown',
    ];

    /**
     * @param  array<string, int>  $ages  personId => age this year
     * @param  array<string, Money>  $incomeBySource  keyed by {@see INCOME_SOURCES}
     * @param  list<Warning>  $warnings
     */
    public function __construct(
        public readonly int $yearIndex,
        public readonly int $calendarYear,
        public readonly array $ages,
        public readonly int $aliveCount,
        public readonly Money $grossIncome,
        public readonly Money $totalTax,
        public readonly Money $netIncome,
        public readonly Money $spendTarget,
        public readonly Money $essentialSpend,
        public readonly Money $shortfallFunded,
        public readonly Money $unmetSpend,
        public readonly bool $essentialsMet,
        public readonly Money $liquidWealth,
        public readonly Money $pensionWealth,
        public readonly Money $propertyWealth,
        public readonly Money $totalWealth,
        public readonly array $incomeBySource = [],
        public readonly array $warnings = [],
    ) {}

    /** The full target spend was met in this year (nothing went unfunded). */
    public function fullSpendMet(): bool
    {
        return $this->unmetSpend->isZero();
    }
}

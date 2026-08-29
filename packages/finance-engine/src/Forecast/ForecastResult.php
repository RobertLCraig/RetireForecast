<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Iht\IhtOutcome;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * The outcome of projecting one path: the year-by-year series plus the headline
 * summary the UI leads with — whether essentials and the full spend were met every
 * year, when (if ever) the money ran out, and the terminal wealth left over.
 *
 * $essentialsAlwaysMet / $fullSpendAlwaysMet are all-or-nothing across the whole path, so a
 * single short year reads identically to a plan that never worked. Read them beside
 * {@see fullSpendYearsMetFraction()} / {@see essentialsYearsMetFraction()}, which say HOW MUCH
 * of the plan held; both are derived from $years, so the pair cannot disagree.
 *
 * Terminal wealth is reported two ways so the asset-rich / cash-poor case reads
 * honestly: $terminalUsableWealth is the spendable part (cash, investments, ISAs
 * and pension pots) and $terminalTotalWealth adds the illiquid primary residence's
 * EQUITY — net of any outstanding mortgage (NNEG-floored), so a rolled-up lifetime
 * mortgage erodes it; a lender's share of the bricks is never reported as wealth.
 *
 * $deathCalendarYears is the modelled calendar year of each person's death
 * (personId => birthYear + death age), so the "when does each life event happen"
 * layer can read it without re-deriving the death age. A person is modelled alive
 * through that year (their income is still counted) and gone the following year.
 *
 * $careCostReal is the total late-life care cost the household bears on this path
 * (net of the means test — local-authority support once the resident's own assets
 * fall to the capital limit), in REAL (today's money); null when care is not
 * modelled (the deterministic and historical views, and any Monte Carlo path with
 * no sampled care spell), so the risk stays visible rather than buried in the
 * success rate. See {@see careCostReal()}.
 *
 * $iht is the Inheritance Tax due across the household's deaths (in real terms), or null
 * when IHT is not modelled ({@see ForecastSettings::$modelIht} off) — so turning the toggle
 * on demonstrably changes the result, closing the collected-but-unconsumed input.
 */
final class ForecastResult
{
    /**
     * @param  list<YearResult>  $years
     * @param  array<string, int>  $deathCalendarYears  personId => modelled year of death
     */
    public function __construct(
        public readonly array $years,
        public readonly bool $essentialsAlwaysMet,
        public readonly bool $fullSpendAlwaysMet,
        public readonly ?int $depletionCalendarYear,
        public readonly Money $terminalTotalWealth,
        public readonly Money $terminalUsableWealth,
        public readonly int $finalCalendarYear,
        public readonly array $deathCalendarYears = [],
        public readonly ?Money $careCostRealValue = null,
        public readonly ?IhtOutcome $iht = null,
    ) {}

    /** The real total care cost incurred on this path (zero if none was modelled). */
    public function careCostReal(): Money
    {
        return $this->careCostRealValue ?? Money::zero();
    }

    /**
     * The share of this path's years in which the full spending target was met, 0.0 to 1.0. The
     * honest companion to $fullSpendAlwaysMet, which is all-or-nothing across the whole path and
     * so reports a fifty-year plan that fell short in one year exactly as it reports one that
     * never funded a penny. DERIVED from $years, never stored, so it cannot drift from the flag.
     * 1.0 for an empty path — no year fell short.
     */
    public function fullSpendYearsMetFraction(): float
    {
        return $this->yearsMetFraction(static fn (YearResult $y): bool => $y->fullSpendMet());
    }

    /** The same measure for the essential floor — the companion the full-spend share is read against. */
    public function essentialsYearsMetFraction(): float
    {
        return $this->yearsMetFraction(static fn (YearResult $y): bool => $y->essentialsMet);
    }

    /** @param  callable(YearResult): bool  $met */
    private function yearsMetFraction(callable $met): float
    {
        if ($this->years === []) {
            return 1.0;
        }

        return count(array_filter($this->years, $met)) / count($this->years);
    }
}

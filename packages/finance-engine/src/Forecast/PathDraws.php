<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Care\CareCostSampler;
use RetireForecast\FinanceEngine\Dto\AssumptionSet;

/**
 * The per-path economic and mortality draws the projector consumes, abstracted so
 * the same projector serves both the deterministic forecast (expected values, a
 * single representative lifespan) and the Monte Carlo (sampled return sequences and
 * sampled death ages). All returns are REAL (above-inflation) fractions, e.g. 0.044.
 */
interface PathDraws
{
    /** Blended real return on invested pots (DC, ISA, GIA) for the given year index. */
    public function investmentRealReturn(int $yearIndex): float;

    /** Real return on cash holdings for the given year index. */
    public function cashRealReturn(int $yearIndex): float;

    /**
     * The NOMINAL annual income yield (dividends/interest) of a GIA portfolio — the
     * fraction of a GIA's value paid out as taxable income each year. Constant across
     * years; the remaining return is capital growth (taxed as CGT on disposal). See
     * {@see AssumptionSet::$investmentIncomeYield}.
     */
    public function investmentIncomeYield(): float;

    /**
     * The annual ongoing charge on INVESTED balances as a fraction (e.g. 0.005 for 0.50%),
     * or 0.0 if no charge is modelled — the platform/administration fee plus the funds'
     * ongoing charges, which the projector deducts from each invested balance after growth.
     * Constant across years and paths: a charge is a price, not a risk, so making it
     * stochastic would add spurious spread. Cash deposits are not charged. The asset-class
     * returns are GROSS of charges, so without this deduction the portfolio is held for
     * free. See {@see AssumptionSet::$investmentCharge}.
     */
    public function investmentChargeRate(): float;

    /** Inflation rate (fraction) for the given year index. */
    public function inflation(int $yearIndex): float;

    /**
     * Real growth of the household's OWN home for the given year index.
     *
     * $meanReal is the property's growth override, when it carries one, and it sets the MEAN the
     * year's variation is drawn around. It does not switch the variation off. Overriding is how
     * somebody says a home is not typical (a park home, a flat in a slow block), so the home that
     * most needs a fan of outcomes is exactly the one an override used to flatten into a line.
     * Null means "use the set's house-growth mean".
     *
     * The sampled house path is an INDEX path, and a single home carries the property-specific
     * risk an index has averaged away, so a stochastic driver widens the index shock by
     * {@see AssumptionSet::singlePropertyVolatilityMultiple()} before handing it over. A
     * deterministic driver has no shock to widen and returns the mean.
     */
    public function propertyGrowthReal(int $yearIndex, ?float $meanReal = null): float;

    /** Real salary growth for the given year index. */
    public function salaryGrowthReal(int $yearIndex): float;

    /** The age at which the given person dies on this path. */
    public function deathAge(string $personId): int;

    /**
     * The person's late-life care cost for the given age, in REAL (today's money) pence, or 0 if
     * they are not in care that year — the GROSS self-funder fee; the projector then applies the
     * means test to what the household actually bears. Non-zero only on Monte Carlo paths where a
     * care spell was sampled (see {@see CareCostSampler}); the deterministic
     * and historical drivers return 0, so care is a modelled risk in the distribution, not in the
     * central estimate.
     */
    public function careAnnualCost(string $personId, int $age): int;

    /**
     * The REAL (above-CPI) annual escalation of self-funder care fees, as a fraction (e.g.
     * 0.02 for CPI + 2%), or 0.0 for flat-real. The projector compounds the sampled care fee
     * ({@see careAnnualCost}) at this rate to the year the spell falls, so care — the fastest-
     * inflating major late-life cost — rises faster than the general CPI drawn by
     * {@see inflation}. See {@see AssumptionSet::$careCostRealGrowth}.
     */
    public function careCostRealGrowth(): float;
}

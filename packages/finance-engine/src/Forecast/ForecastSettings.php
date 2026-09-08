<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Care\CareCostSampler;
use RetireForecast\FinanceEngine\Dto\HousingAction;
use RetireForecast\FinanceEngine\Dto\MortgageMaturityAction;
use RetireForecast\FinanceEngine\Dto\RelationshipStatus;
use RetireForecast\FinanceEngine\Housing\SellingCostComponent;
use RetireForecast\FinanceEngine\Iht\InheritanceTaxCalculator;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Mortality\PlanningHorizon;
use RetireForecast\FinanceEngine\StatePension\StatePensionUprating;

/**
 * Settings that shape a forecast run but are not part of the household or the
 * economic assumptions: the start year, how invested pots are allocated, the
 * drawdown strategy, and the tax-year basis.
 *
 * $freezeEndYear is the year UK income-tax thresholds stop being frozen and are
 * assumed to rise with inflation again (currently April 2031); before then, frozen
 * nominal thresholds against inflating incomes produce real fiscal drag, which the
 * projector models.
 *
 * $annualRent (with $rentInflationReal) models the rent leg of a "sell and rent"
 * scenario: an essential expense on top of the household's spend that grows at its
 * own real rate rather than CPI. Null means the household is not renting.
 *
 * $modelCareCost, when true, makes the Monte Carlo sample a late-life care spell per
 * person (see {@see CareCostSampler}), so the
 * distribution reflects the fat-tail risk of care fees. Default false, so existing
 * runs are unchanged; the deterministic and historical views never model care. The
 * decision-support care lever flips this off vs on via {@see withModelCareCost} to pin
 * the two futures side by side.
 *
 * $sellingCosts is the cost basis for an in-projection forced sale (a home whose mortgage
 * is called for redemption with {@see MortgageMaturityAction::ForcedSale}):
 * the projector has no {@see HousingAction}, so the entered
 * components ride here. Null falls back to the engine default rate. Only consumed at the
 * forced sale; irrelevant to every other run.
 *
 * $modelIht, when true, makes the projector value the estate at each death and compute the
 * Inheritance Tax due (relationship-status aware — see {@see RelationshipStatus}),
 * surfaced as {@see ForecastResult::$iht}. Default false, so an existing run is unchanged
 * (the IHT toggle was collected but not consumed before this). $homeToDescendants says the
 * home is left to direct descendants, which is what unlocks the residence nil-rate band on
 * the final death; default true (the common case when a household owns a home).
 *
 * $useIsaAllowance, when true (the default), has the household use each person's unused ISA
 * subscription allowance each year on money they already hold in a taxable General Investment
 * Account ("bed and ISA"). It is ON by default because the absence of it UNDERSTATES every plan
 * that sells a home and invests the proceeds: those proceeds land in a GIA, a real household
 * would shelter them, and modelling them never doing so charges tax they would not pay. It is a
 * modelled ACTION rather than an economic assumption, so it is disclosed on the results page as
 * an assumed figure and can be turned off here for a household that would not take it.
 *
 * $statePensionUprating (with $tripleLockUntilYear) is how long the triple lock is assumed to
 * survive: {@see StatePensionUprating}. It rides here rather than on the AssumptionSet because it
 * is a POLICY choice about the future, not an economic series with a source and a mean, and it
 * sits beside the other policy choices ($modelIht, $useIsaAllowance) the reader makes about what
 * the model should assume happens. It moves the State Pension AND the Pension Credit guarantee,
 * which is uprated by the same running factor.
 */
final class ForecastSettings
{
    /**
     * @param  list<SellingCostComponent>|null  $sellingCosts
     */
    public function __construct(
        public readonly int $baseYear,
        public readonly string $baseTaxYear = '2026-27',
        public readonly DrawdownStrategy $drawdownStrategy = DrawdownStrategy::TaxEfficient,
        public readonly ?PortfolioAllocation $allocation = null,
        public readonly int $freezeEndYear = 2031,
        public readonly ?Money $annualRent = null,
        public readonly ?Percent $rentInflationReal = null,
        public readonly bool $modelCareCost = false,
        public readonly ?array $sellingCosts = null,
        public readonly bool $modelIht = false,
        public readonly bool $homeToDescendants = true,
        public readonly bool $useIsaAllowance = true,
        public readonly StatePensionUprating $statePensionUprating = StatePensionUprating::TripleLock,
        public readonly ?int $tripleLockUntilYear = null,
        /**
         * The income-tax rate the person who INHERITS an unused pension pot is assumed to pay on
         * drawing it, where the member died at or after 75. Null = the engine's own adverse default
         * ({@see InheritanceTaxCalculator::DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS}), disclosed as an
         * assumed figure. It is a fact about somebody outside the household, so it can only ever be
         * an assumption, but it sets half the cost of preserving a pot rather than spending it.
         */
        public readonly ?Percent $beneficiaryMarginalRate = null,
        /**
         * How long the deterministic plan has to last: a named percentile of the age at death of
         * the LAST surviving member of the household (board card 0061). The default is the
         * cautious 75th, because the median it replaced is a coin flip, and a plan ranked on a
         * coin-flip lifespan leaves roughly even odds of a decade of unfunded life.
         */
        public readonly PlanningHorizon $planningHorizon = PlanningHorizon::DEFAULT,
    ) {}

    /**
     * Is the planning horizon the ENGINE's own default rather than one the reader chose? It moves
     * the depletion year, the estate and every affordability answer, so a reader who did not pick
     * it has to be told which one is running.
     */
    public function planningHorizonIsAssumed(): bool
    {
        return $this->planningHorizon === PlanningHorizon::DEFAULT;
    }

    /**
     * The beneficiary's assumed marginal rate actually in force: the reader's, or the engine's
     * adverse default read from the constant that owns it.
     */
    public function beneficiaryMarginalRate(): Percent
    {
        return $this->beneficiaryMarginalRate
            ?? Percent::fromBasisPoints(InheritanceTaxCalculator::DEFAULT_BENEFICIARY_MARGINAL_RATE_BPS);
    }

    /** Is that rate the ENGINE's, rather than one the reader chose? */
    public function beneficiaryMarginalRateIsAssumed(): bool
    {
        return $this->beneficiaryMarginalRate === null;
    }

    public function allocation(): PortfolioAllocation
    {
        return $this->allocation ?? PortfolioAllocation::cautious40_60();
    }

    /**
     * Is the allocation in play one the ENGINE supplied? True whenever the caller passed none,
     * which is the condition the no-invisible-figures disclosure is gated on.
     */
    public function allocationIsAssumed(): bool
    {
        return $this->allocation === null;
    }

    /**
     * Is the State Pension uprating in play the ENGINE's own default rather than a choice the
     * reader made? The default is the full triple lock, which is the optimistic branch of
     * contested policy, so a reader who did not choose it has to be told it was chosen for them.
     */
    public function statePensionUpratingIsAssumed(): bool
    {
        return $this->statePensionUprating === StatePensionUprating::TripleLock;
    }

    /**
     * A copy with the late-life care-cost modelling toggled. Every other setting is preserved, so
     * the two states differ only in whether the Monte Carlo samples a care spell — the pin the
     * decision-support care lever compares off against on.
     */
    public function withModelCareCost(bool $on): self
    {
        return new self(
            $this->baseYear, $this->baseTaxYear, $this->drawdownStrategy, $this->allocation,
            $this->freezeEndYear, $this->annualRent, $this->rentInflationReal, $on,
            $this->sellingCosts, $this->modelIht, $this->homeToDescendants, $this->useIsaAllowance,
            $this->statePensionUprating, $this->tripleLockUntilYear, $this->beneficiaryMarginalRate,
            $this->planningHorizon,
        );
    }
}

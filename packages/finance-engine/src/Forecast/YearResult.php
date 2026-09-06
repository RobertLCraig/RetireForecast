<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

use RetireForecast\FinanceEngine\Benefits\CouncilTax;
use RetireForecast\FinanceEngine\Benefits\HousingBenefit;
use RetireForecast\FinanceEngine\Benefits\SupportForMortgageInterest;
use RetireForecast\FinanceEngine\Iht\EstateValuer;
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
 * $unmetOneOffSpend is the part of $unmetSpend that is a one-off CAPITAL lump (an unfunded
 * home purchase, a mortgage redeemed from capital) rather than the household's recurring
 * budget. Recurring spend is funded first — the same order {@see $essentialsMet} already
 * assumes — so a shortfall is charged against the year's one-offs before anything else, and
 * {@see fullSpendMet()} judges the RECURRING budget only. Without the split, one unfunded
 * pound of a year-0 purchase failed a fifty-year plan on every path.
 * The lump is not swept under the carpet: it stays inside $unmetSpend (so the net-position
 * fan and the audit still see it) and the year carries a {@see WarningCode::UNFUNDED_ONE_OFF_COST}
 * warning naming the cost. Null on a hand-built year or one restored from an older stored
 * result, which reads as "none identified".
 *
 * $essentialSpend is the essential floor within $spendTarget (rent or property running
 * costs included, survivor factor applied) — the bar the "essentials always met" measure
 * is judged against, and the figure the income-floor readout compares secure income to.
 *
 * $incomeBySource breaks the year's inflows into the canonical {@see INCOME_SOURCES}
 * (real money) so the drill-down cashflow ladder can show where money came from and
 * how any shortfall was funded. Every source that should reach spendable cash appears
 * here, which is the visual guard against silently dropping one (e.g. tax-free DLA).
 *
 * $investmentGrowth is the year's CAPITAL appreciation left inside the invested pots —
 * share/fund price growth (GIA at capital-only, ISA and pensions at the full return),
 * over and above the {@see INCOME_SOURCES} `investment_income` (interest + dividends) paid
 * out and taxed each year. It is not spendable cash this year (it compounds in the pot,
 * taxed as CGT only on a later GIA disposal), so it is carried separately from income —
 * it is the "where the rest of the gains come from" the wealth line reflects but the
 * income breakdown otherwise would not. Can be negative in a down year. It is GROSS of
 * $investmentCharges, so opening balance + growth - charges reconciles to the closing one.
 *
 * $investmentCharges is what holding the invested money COST this year — the platform/
 * administration fee plus the funds' ongoing charges, taken out of the DC pots, ISAs and
 * GIAs (cash deposits bear none). Zero when no charge is modelled. Carried as its own
 * figure rather than netted silently into $investmentGrowth, because a charge the reader
 * cannot see is indistinguishable from one we invented.
 *
 * $nominal is this same year in the projector's own PRE-DEFLATION pounds: the cash actually
 * changing hands in that calendar year, before the division by the price level that produces
 * every figure above. It is built alongside the real year from the identical nominal integers,
 * so a nominal-pounds view is the engine's own arithmetic and never a presenter re-inflating a
 * deflated figure (which would drift from it by the rounding the deflation threw away). It is
 * null on a hand-built {@see self} (a fixture, or a year restored from an older stored result),
 * so a caller offering the nominal view must check for it rather than showing real figures under
 * a nominal label. A nominal year carries no twin of its own.
 */
final class YearResult
{
    /**
     * The canonical income-source keys, in display order: earned salary; defined
     * benefit; State Pension; other taxable income (annuity, rental); taxable
     * investment income (GIA dividends + cash interest paid out, A5); tax-free
     * income (e.g. DLA); means-tested benefit (Pension Credit Guarantee Credit);
     * pension tax-free lump sums; taxable pension drawdown (planned + drawn to meet a
     * shortfall); capital drawn from savings/ISA/GIA; and documented one-off capital
     * receipts (a family gift / inheritance / outside-asset sale — tax-free, one-off,
     * so never part of the secure-income floor); and an employer death-in-service lump
     * sum paid to the survivor (likewise one-off, and shown GROSS — any tax on it is in
     * the year's total tax, as for every other taxable source).
     */
    public const INCOME_SOURCES = [
        'salary',
        'defined_benefit',
        'state_pension',
        'other_taxable',
        'investment_income',
        'tax_free_income',
        'means_tested_benefit',
        'pension_lump_sum',
        'pension_drawdown',
        'asset_drawdown',
        'capital_receipt',
        'death_in_service',
    ];

    /**
     * Total wealth: liquid + pension + home EQUITY (property net of everything secured on it,
     * the mortgage and any {@see smiBalance()} charge, NNEG-floored) — the figure every
     * "includes the home" surface shows. Derived in the
     * constructor from the reported legs, so it can never drift from them and never
     * counts bricks a lender already owns: an unpaid lifetime-mortgage roll-up visibly
     * erodes the wealth line. Gross property remains available as {@see $propertyWealth}
     * for the equity breakdown; the estate at death nets the same way ({@see EstateValuer}).
     */
    public readonly Money $totalWealth;

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
        public readonly array $incomeBySource = [],
        public readonly array $warnings = [],
        public readonly ?Money $investmentGrowth = null,
        public readonly ?Money $mortgageBalance = null,
        public readonly ?Money $investmentCharges = null,
        public readonly ?self $nominal = null,
        public readonly ?Money $isaSheltered = null,
        public readonly ?Money $unmetOneOffSpend = null,
        public readonly ?Money $smiBalance = null,
        public readonly ?Money $councilTax = null,
        public readonly ?Money $housingBenefit = null,
    ) {
        $this->totalWealth = $liquidWealth->plus($pensionWealth)->plus($this->homeEquity());
    }

    /**
     * The full RECURRING target spend was met in this year. A one-off capital lump the year could
     * not fund ({@see $unmetOneOffSpend}) is excluded, because it is a failure of that lump — named
     * by its own warning — and not of the household's ordinary spending.
     */
    public function fullSpendMet(): bool
    {
        return $this->unmetSpend->minus($this->unmetOneOffSpend())->minZero()->isZero();
    }

    /** The part of this year's unmet spend that is an unfunded one-off capital lump (zero if none). */
    public function unmetOneOffSpend(): Money
    {
        return $this->unmetOneOffSpend ?? Money::zero();
    }

    /**
     * The outstanding mortgage on the home this year (real money, zero if none). Non-zero and
     * GROWING when a lifetime mortgage rolls up unpaid; level when it is repaid/serviced.
     */
    public function mortgageBalance(): Money
    {
        return $this->mortgageBalance ?? Money::zero();
    }

    /**
     * The Support for Mortgage Interest charge standing against the home this year (real money,
     * zero if none). It is a SECOND secured balance beside {@see mortgageBalance()}, never folded
     * into it: DWP lends the interest it meets on an eligible mortgage, plus a pension-age
     * claimant's service charge and ground rent, and takes its own charge for what it has paid.
     * {@see SupportForMortgageInterest}.
     */
    public function smiBalance(): Money
    {
        return $this->smiBalance ?? Money::zero();
    }

    /**
     * The council tax actually charged this year, after the single-person discount, any disabled
     * band reduction and any Council Tax Reduction — zero when the household entered none, or
     * still holds it inside its running costs. Reported on its own because it is the one running
     * cost that shrinks, and a reader cannot check a discount they cannot see.
     * {@see CouncilTax}.
     */
    public function councilTax(): Money
    {
        return $this->councilTax ?? Money::zero();
    }

    /**
     * The Housing Benefit that met this year's rent (real money, zero when none was awarded or
     * the household is not renting). Like Council Tax Reduction it is reported as a REDUCTION in
     * what the year charges rather than as income: it is paid towards a rent bill and can never
     * be spent on anything else, so crediting it as income would let the household eat it.
     * Reported on its own so a reader can see the help the rent line already assumes.
     * {@see HousingBenefit}.
     */
    public function housingBenefit(): Money
    {
        return $this->housingBenefit ?? Money::zero();
    }

    /**
     * Home equity net of everything secured on it — the mortgage and any Support for Mortgage
     * Interest charge — floored at zero (the No-Negative-Equity Guarantee: a rolled-up balance
     * above the home's value is not a negative estate, and DWP writes off an SMI shortfall the
     * same way). The same definition {@see EstateValuer} uses at death.
     */
    public function homeEquity(): Money
    {
        return $this->propertyWealth->minus($this->mortgageBalance())->minus($this->smiBalance())->minZero();
    }

    /** This year's capital growth left in the invested pots (zero if not tracked). */
    public function investmentGrowth(): Money
    {
        return $this->investmentGrowth ?? Money::zero();
    }

    /** What holding the invested money cost this year (zero if no charge is modelled). */
    public function investmentCharges(): Money
    {
        return $this->investmentCharges ?? Money::zero();
    }

    /**
     * What was moved out of a taxable General Investment Account into an ISA this year
     * ("bed and ISA"), zero if none was. Not income and not spend: the same pounds simply stop
     * being taxable, so it is carried on its own rather than folded into any flow, and it is
     * what lets a screen state the sheltering the model performed instead of leaving it invisible.
     */
    public function isaSheltered(): Money
    {
        return $this->isaSheltered ?? Money::zero();
    }

    /**
     * A copy of this year with its investment (capital) growth and the ongoing charges taken
     * out of the pots set — both attached after growth is applied. $nominal replaces the
     * pre-deflation twin (the caller attaches the same flows in nominal pounds); omitted, the
     * existing twin is carried, so a nominal year's own copy stays twinless.
     */
    public function withInvestmentGrowth(Money $investmentGrowth, ?Money $investmentCharges = null, ?self $nominal = null): self
    {
        return new self(
            $this->yearIndex, $this->calendarYear, $this->ages, $this->aliveCount,
            $this->grossIncome, $this->totalTax, $this->netIncome, $this->spendTarget,
            $this->essentialSpend, $this->shortfallFunded, $this->unmetSpend, $this->essentialsMet,
            $this->liquidWealth, $this->pensionWealth, $this->propertyWealth,
            $this->incomeBySource, $this->warnings, $investmentGrowth, $this->mortgageBalance,
            $investmentCharges ?? $this->investmentCharges,
            $nominal ?? $this->nominal,
            $this->isaSheltered,
            $this->unmetOneOffSpend,
            $this->smiBalance,
            $this->councilTax,
            $this->housingBenefit,
        );
    }
}

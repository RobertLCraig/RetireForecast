<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

use RetireForecast\FinanceEngine\Dto\DisabilityAwardRate;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Pension Credit Guarantee Credit: the means-tested top-up that lifts a pensioner
 * household's income to the "appropriate minimum guarantee".
 *
 *   Guarantee Credit = max(0, applicable amount − (assessable income + capital tariff))
 *
 * where the applicable amount is the Standard Minimum Guarantee for the household
 * (single or couple) plus any severe-disability and carer additions, and the capital
 * tariff is the deemed income from assessable capital ({@see CapitalAssessment} — £1 a
 * week per £500 above the £10,000 disregard). Disability benefits (DLA / AA / PIP) are
 * disregarded as income, so they do not reduce the award.
 *
 * Weekly money throughout (the benefit's natural unit); the caller multiplies by the
 * tax year's weeks for an annual figure. {@see PensionCreditResult}.
 *
 * v1 scope (flagged): Guarantee Credit only. Savings Credit is NOT modelled, and the reason
 * matters more than the omission: it is closed to a person who reached State Pension age on or
 * after 6 April 2016, but for a COUPLE it stays open while EITHER member reached State Pension
 * age before that date (and, in the usual case, is still receiving it). The award is small and
 * usually nil at the incomes this tool models, which is why it is out of scope, not because the
 * door is shut, which is what this docblock used to imply and would lead a reader to skip the
 * question entirely (board card 0051).
 *
 * The caller (the projector) applies the eligibility rules. For the SDP a couple needs BOTH
 * partners on a qualifying disability benefit, and "qualifying" means the CARE side of the award
 * ({@see DisabilityAwardRate}). This class applies the right
 * amount: the couple rate is twice the single rate, and the carer addition is per carer.
 *
 * Still not modelled: the "no non-dependant adult / lives alone" SDP test beyond the partner; the
 * *paid*-Carer's-Allowance-removes-the-SDP interaction (the modelled carer route is underlying
 * entitlement, which does not remove it); the earnings disregard and the net-of-tax treatment of
 * earnings; the qualifying-age and mixed-age-couple gate is the caller's responsibility.
 */
final class PensionCreditCalculator
{
    private readonly CapitalAssessment $capitalAssessment;

    public function __construct(private readonly TaxYearConfig $config)
    {
        $this->capitalAssessment = new CapitalAssessment($config);
    }

    /**
     * The weekly appropriate minimum guarantee for the household: the Standard Minimum
     * Guarantee (single or couple) plus the severe-disability / carer additions when they
     * apply. This is the income level Guarantee Credit tops the household up to.
     *
     * $severeDisability means "the household qualifies" (the caller owns the eligibility test).
     * It is paid at the couple rate (twice the single rate) for a qualifying couple, meaning both
     * partners receive a qualifying disability benefit, and at the single rate otherwise.
     *
     * $carers is a COUNT, not a flag: the carer addition is per carer, so a couple where each
     * member cares for the other carries two of them. Passing a boolean would have made two
     * carers indistinguishable from one, which is the defect board card 0051 names.
     */
    public function applicableAmountWeekly(bool $isCouple, bool $severeDisability = false, int $carers = 0): Money
    {
        $benefits = $this->config->benefits;

        $amount = $isCouple
            ? $benefits->standardMinimumGuaranteeCoupleWeekly
            : $benefits->standardMinimumGuaranteeSingleWeekly;

        if ($severeDisability) {
            $sdp = $benefits->severeDisabilityAdditionWeekly;
            $amount = $amount->plus($isCouple ? $sdp->times(2) : $sdp);
        }
        if ($carers > 0) {
            $amount = $amount->plus($benefits->carerAdditionWeekly->times($carers));
        }

        return $amount;
    }

    /**
     * The award given an explicit applicable amount — so a caller projecting forward can
     * pass an uprated guarantee while the capital tariff keeps the (frozen) statutory
     * thresholds. Assessable income should already exclude disregarded benefits (DLA/AA)
     * and actual investment income (capital is assessed via the tariff instead).
     */
    public function award(Money $applicableAmountWeekly, Money $assessableIncomeWeekly, Money $assessableCapital): PensionCreditResult
    {
        $tariff = $this->capitalAssessment->assess($assessableCapital)->tariffIncomeWeekly;
        $totalIncome = $assessableIncomeWeekly->plus($tariff);
        $guaranteeCredit = $applicableAmountWeekly->minus($totalIncome)->minZero();

        return new PensionCreditResult($guaranteeCredit, $applicableAmountWeekly, $totalIncome, $tariff);
    }

    /**
     * Convenience for tests / worked examples in base-year money (the config's own
     * guarantee figures, no uprating).
     */
    public function assess(
        bool $isCouple,
        Money $assessableIncomeWeekly,
        Money $assessableCapital,
        bool $severeDisability = false,
        int $carers = 0,
    ): PensionCreditResult {
        return $this->award(
            $this->applicableAmountWeekly($isCouple, $severeDisability, $carers),
            $assessableIncomeWeekly,
            $assessableCapital,
        );
    }
}

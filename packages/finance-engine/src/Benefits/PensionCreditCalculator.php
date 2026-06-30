<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Benefits;

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
 * v1 scope (flagged): Guarantee Credit only (no Savings Credit, largely closed to those
 * reaching State Pension age after April 2016); the severe-disability/carer additions are
 * applied from a flag, not the full eligibility rules (the Carer's-Allowance interaction
 * with the SDP, and the "lives alone" test, are not modelled); the qualifying-age and
 * mixed-age-couple gate is the caller's responsibility.
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
     */
    public function applicableAmountWeekly(bool $isCouple, bool $severeDisability = false, bool $carer = false): Money
    {
        $benefits = $this->config->benefits;

        $amount = $isCouple
            ? $benefits->standardMinimumGuaranteeCoupleWeekly
            : $benefits->standardMinimumGuaranteeSingleWeekly;

        if ($severeDisability) {
            $amount = $amount->plus($benefits->severeDisabilityAdditionWeekly);
        }
        if ($carer) {
            $amount = $amount->plus($benefits->carerAdditionWeekly);
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
        bool $carer = false,
    ): PensionCreditResult {
        return $this->award(
            $this->applicableAmountWeekly($isCouple, $severeDisability, $carer),
            $assessableIncomeWeekly,
            $assessableCapital,
        );
    }
}

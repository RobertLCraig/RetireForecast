<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Property\AmortisationSchedule;

/**
 * The terms of a capital-and-interest ("repayment") mortgage: the borrower pays a level monthly
 * instalment covering the month's interest and repaying some capital, so the balance AMORTISES
 * to zero over the term.
 *
 * This is the third mortgage shape the engine models, alongside the two already on
 * {@see Property}: a STATIC balance (an interest-only / retirement-interest-only loan, whose
 * interest is an expense line and whose capital is never repaid) and a ROLLED-UP balance
 * ({@see Property::$mortgageRollUpRate} — a lifetime mortgage with no payments). A repayment
 * mortgage is the ordinary residential product and behaves like neither:
 *  - the balance FALLS every year (otherwise net wealth and the IHT estate are understated by
 *    the capital repaid, and by the whole loan once the term has run);
 *  - the payment is FIXED NOMINAL — it does not rise with CPI, so in real terms it falls over
 *    the term (an expense line is a real-terms figure the projector re-inflates every year);
 *  - the payment does not shrink when one partner dies — the survivor owes the lender the same
 *    instalment, so the survivor-spend factor must not touch it;
 *  - the payment ENDS at the end of the term, leaving the household debt-free.
 *
 * These are the TERMS only. The amount borrowed is {@see Property::$outstandingMortgage} — the
 * one home for "what is owed", shared with the static and rolled-up shapes, so an amortising
 * balance can never drift from the loan it is amortising. The two are combined by
 * {@see AmortisationSchedule}.
 *
 * $termMonths is the full term. $firstPaymentYear / $firstPaymentMonth date payment number one,
 * so instalments land in the right calendar years — a mortgage completing in August pays only
 * four instalments in its first calendar year, not twelve.
 *
 * $ratePeriods are the rate tiers in order ({@see MortgageRatePeriod}); the last must be
 * open-ended (null months) so the schedule always reaches the end of the term.
 */
final class RepaymentMortgageTerms
{
    /**
     * @param  list<MortgageRatePeriod>  $ratePeriods
     */
    public function __construct(
        public readonly int $termMonths,
        public readonly int $firstPaymentYear,
        public readonly int $firstPaymentMonth,
        public readonly array $ratePeriods,
    ) {
        if ($termMonths < 1) {
            throw new \InvalidArgumentException('A repayment mortgage must run for at least one month.');
        }
        if ($firstPaymentMonth < 1 || $firstPaymentMonth > 12) {
            throw new \InvalidArgumentException('The first payment month must be 1-12.');
        }
        if ($ratePeriods === []) {
            throw new \InvalidArgumentException('A repayment mortgage needs at least one rate period.');
        }
        $last = $ratePeriods[array_key_last($ratePeriods)];
        if ($last->months !== null) {
            throw new \InvalidArgumentException('The final mortgage rate period must be open-ended (null months) so it runs to the end of the term.');
        }
        $fixed = 0;
        foreach ($ratePeriods as $period) {
            $fixed += $period->months ?? 0;
        }
        if ($fixed >= $termMonths) {
            throw new \InvalidArgumentException('The fixed-length mortgage rate periods must be shorter than the term, leaving months for the final period.');
        }
    }

    /**
     * The calendar year the final instalment falls in — after which the household owns the home
     * outright and pays nothing.
     */
    public function finalPaymentYear(): int
    {
        return $this->firstPaymentYear + intdiv($this->firstPaymentMonth - 1 + $this->termMonths - 1, 12);
    }
}

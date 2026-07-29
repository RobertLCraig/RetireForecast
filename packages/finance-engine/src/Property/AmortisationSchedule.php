<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Property;

use RetireForecast\FinanceEngine\Dto\Property;
use RetireForecast\FinanceEngine\Dto\RepaymentMortgageTerms;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * The month-by-month amortisation of a capital-and-interest mortgage — a principal (the amount
 * owed, {@see Property::$outstandingMortgage}) put through its
 * {@see RepaymentMortgageTerms} — aggregated to the calendar years the projector works in.
 *
 * Convention — the UK lender one, so a schedule can be checked against a real European
 * Standardised Information Sheet (ESIS) illustration line for line:
 *  - the monthly interest rate is the NOMINAL annual rate divided by 12 (not an effective
 *    annual rate converted to a monthly equivalent);
 *  - each month charges interest on the opening balance, and the rest of the instalment
 *    repays capital;
 *  - at the start of each rate tier the instalment is recomputed as the annuity that clears
 *    the THEN-outstanding balance over the REMAINING months at the new rate — which is what
 *    produces the payment step a lender illustrates at the end of a fixed-rate deal;
 *  - the final instalment is trued up to clear the balance exactly, so the loan always ends
 *    at zero rather than at a few pence of rounding residue.
 *
 * Everything is integer pence. Interest is rounded to the penny each month, as a lender's own
 * schedule does, so the balance tracks a real illustration rather than a closed-form curve.
 */
final class AmortisationSchedule
{
    /** @var array<int, int> calendar year => instalments paid that year, pence */
    private array $paymentByYear = [];

    /** @var array<int, int> calendar year => interest charged that year, pence */
    private array $interestByYear = [];

    /** @var array<int, int> calendar year => balance outstanding at the END of that year, pence */
    private array $closingByYear = [];

    /** @var list<array{month: int, calendarYear: int, payment: int, interest: int, capital: int, balance: int}> */
    private array $rows = [];

    private function __construct(
        private readonly int $principalPence,
        private readonly int $firstPaymentYear,
        private readonly int $finalPaymentYear,
    ) {}

    public static function for(Money $principal, RepaymentMortgageTerms $terms): self
    {
        $schedule = new self(
            $principal->pence,
            $terms->firstPaymentYear,
            $terms->finalPaymentYear(),
        );

        $balance = $principal->pence;
        $monthIndex = 0;

        foreach ($terms->ratePeriods as $period) {
            if ($balance <= 0 || $monthIndex >= $terms->termMonths) {
                break;
            }

            $monthlyRate = $period->annualRate->asFraction() / 12.0;
            $remaining = $terms->termMonths - $monthIndex;
            $monthsInTier = min($period->months ?? $remaining, $remaining);

            // The instalment for this tier: the annuity clearing today's balance over the
            // whole REMAINING term (not just this tier) at this tier's rate.
            $instalment = self::annuity($balance, $monthlyRate, $remaining);

            for ($m = 0; $m < $monthsInTier; $m++) {
                $interest = (int) round($balance * $monthlyRate);
                $isFinalInstalment = $monthIndex === $terms->termMonths - 1;

                if ($isFinalInstalment || $instalment - $interest >= $balance) {
                    // True the last payment up (or down) so the balance lands exactly on zero.
                    $capital = $balance;
                    $payment = $balance + $interest;
                } else {
                    $payment = $instalment;
                    $capital = $instalment - $interest;
                }

                $balance -= $capital;
                $year = $terms->firstPaymentYear
                    + intdiv($terms->firstPaymentMonth - 1 + $monthIndex, 12);

                $schedule->paymentByYear[$year] = ($schedule->paymentByYear[$year] ?? 0) + $payment;
                $schedule->interestByYear[$year] = ($schedule->interestByYear[$year] ?? 0) + $interest;
                $schedule->closingByYear[$year] = $balance;
                $schedule->rows[] = [
                    'month' => $monthIndex + 1,
                    'calendarYear' => $year,
                    'payment' => $payment,
                    'interest' => $interest,
                    'capital' => $capital,
                    'balance' => $balance,
                ];

                $monthIndex++;
                if ($balance <= 0) {
                    break 2;
                }
            }
        }

        return $schedule;
    }

    /**
     * The level monthly instalment that clears $balance over $months at $monthlyRate — the
     * standard annuity formula, rounded to the penny (a lender quotes whole pence).
     */
    private static function annuity(int $balance, float $monthlyRate, int $months): int
    {
        if ($months < 1) {
            return $balance;
        }
        if ($monthlyRate <= 0.0) {
            return (int) ceil($balance / $months);
        }

        return (int) round($balance * $monthlyRate / (1.0 - (1.0 + $monthlyRate) ** (-$months)));
    }

    /**
     * The balance outstanding at the START of $calendarYear — the projector's convention for a
     * year's reported mortgage balance (a year shows what is owed as it opens, matching the
     * static and rolled-up balances).
     */
    public function openingBalanceIn(int $calendarYear): Money
    {
        if ($calendarYear <= $this->firstPaymentYear) {
            return Money::fromPence($this->principalPence);
        }

        return Money::fromPence($this->closingByYear[$calendarYear - 1] ?? 0);
    }

    /** Total instalments falling in $calendarYear — fixed nominal, zero once the term has run. */
    public function paymentIn(int $calendarYear): Money
    {
        return Money::fromPence($this->paymentByYear[$calendarYear] ?? 0);
    }

    /**
     * The interest slice of $calendarYear's instalments — the finance cost for the buy-to-let
     * finance-cost tax reducer, which relieves interest only, never capital repaid.
     */
    public function interestIn(int $calendarYear): Money
    {
        return Money::fromPence($this->interestByYear[$calendarYear] ?? 0);
    }

    public function isRunningIn(int $calendarYear): bool
    {
        return $calendarYear >= $this->firstPaymentYear && $calendarYear <= $this->finalPaymentYear;
    }

    public function finalPaymentYear(): int
    {
        return $this->finalPaymentYear;
    }

    /** Every instalment paid over the life of the loan — capital plus interest. */
    public function totalRepaid(): Money
    {
        return Money::fromPence(array_sum($this->paymentByYear));
    }

    public function totalInterest(): Money
    {
        return Money::fromPence(array_sum($this->interestByYear));
    }

    /**
     * The raw monthly schedule, for checking against a lender's illustration.
     *
     * @return list<array{month: int, calendarYear: int, payment: int, interest: int, capital: int, balance: int}>
     */
    public function rows(): array
    {
        return $this->rows;
    }
}

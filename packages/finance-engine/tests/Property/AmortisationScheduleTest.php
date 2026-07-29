<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Property;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Dto\MortgageRatePeriod;
use RetireForecast\FinanceEngine\Dto\RepaymentMortgageTerms;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Property\AmortisationSchedule;

/**
 * Worked example: a real European Standardised Information Sheet (ESIS) — LiveMore Capital
 * "Livemore 1 - (577) Standard Mortgage C&I 5 Years Fixed", quoted 29 July 2026.
 *
 *   £160,000 over 16 years (192 monthly payments), first payment September 2026
 *   6.23% fixed for 60 months  -> £1,318.54 a month
 *   7.24% (SVR) for 132 months -> £1,384.65 a month
 *   total interest £101,886.01, total repaid £261,886.01, cleared August 2042
 *
 * The lender's own illustrative repayment table is reproduced below and asserted against. This
 * is the mortgage counterpart of the HMRC worked examples: an independently produced schedule
 * the engine must match, not a self-consistent fixture.
 *
 * Tolerance: the lender's table carries its own per-month rounding artefacts (its interest
 * column and its balance column disagree by a penny on row 1), so intermediate balances are
 * asserted to the pound. The figures that are contractual — each tier's monthly instalment, the
 * final year, and a zero balance at the end of the term — are asserted exactly.
 */
final class AmortisationScheduleTest extends TestCase
{
    /** The ESIS quote's terms. */
    private function esisTerms(): RepaymentMortgageTerms
    {
        return new RepaymentMortgageTerms(
            termMonths: 192,
            firstPaymentYear: 2026,
            firstPaymentMonth: 9,
            ratePeriods: [
                new MortgageRatePeriod(Percent::fromPercent(6.23), 60),
                new MortgageRatePeriod(Percent::fromPercent(7.24)),
            ],
        );
    }

    private function esisSchedule(): AmortisationSchedule
    {
        return AmortisationSchedule::for(Money::fromPounds(160_000), $this->esisTerms());
    }

    /**
     * The lender's illustrative repayment table: payment number => [instalment, balance
     * remaining after it], in pounds.
     *
     * @return array<int, array{float, float}>
     */
    public static function esisRepaymentTable(): array
    {
        return [
            1 => [1318.54, 159512.12],
            2 => [1318.54, 159021.72],
            3 => [1318.54, 158528.76],
            4 => [1318.54, 158033.25],
            12 => [1318.54, 153975.39],
            24 => [1318.54, 147564.55],
            36 => [1318.54, 140742.70],
            48 => [1318.54, 133483.51],
            60 => [1318.54, 125758.93],
            72 => [1384.65, 117993.79],
            84 => [1384.65, 109647.42],
            96 => [1384.65, 100676.31],
            108 => [1384.65, 91033.70],
            120 => [1384.65, 80669.33],
            132 => [1384.65, 69529.17],
            144 => [1384.65, 57555.15],
            156 => [1384.65, 44684.86],
            168 => [1384.65, 30851.21],
            180 => [1384.65, 15982.09],
            192 => [1384.65, 0.00],
        ];
    }

    public function test_it_reproduces_the_lenders_illustrative_repayment_table(): void
    {
        $rows = $this->esisSchedule()->rows();
        $this->assertCount(192, $rows, 'the schedule must run the full 192-payment term');

        $byMonth = [];
        foreach ($rows as $row) {
            $byMonth[$row['month']] = $row;
        }

        foreach (self::esisRepaymentTable() as $month => [$instalment, $balance]) {
            $row = $byMonth[$month];

            // The final instalment is trued up to clear the balance exactly, so it differs from
            // the level payment by a few pence — the lender does the same.
            if ($month < 192) {
                $this->assertSame(
                    (int) round($instalment * 100),
                    $row['payment'],
                    "instalment at payment {$month} must match the ESIS to the penny",
                );
            }

            $this->assertEqualsWithDelta(
                (int) round($balance * 100),
                $row['balance'],
                100,
                "balance after payment {$month} must match the ESIS to the pound",
            );
        }
    }

    public function test_the_payment_steps_when_the_fixed_deal_reverts_to_the_variable_rate(): void
    {
        $rows = $this->esisSchedule()->rows();

        // Payments 1-60 at the 6.23% fixed rate, 61-192 at the 7.24% reversion rate. The step is
        // the annuity recomputed on the then-balance over the then-remaining term.
        $this->assertSame(131_854, $rows[59]['payment'], 'the last fixed-rate instalment');
        $this->assertSame(138_465, $rows[60]['payment'], 'the first reverted instalment');
    }

    public function test_the_loan_clears_exactly_at_the_end_of_the_term(): void
    {
        $schedule = $this->esisSchedule();
        $rows = $schedule->rows();

        $this->assertSame(0, $rows[191]['balance'], 'a repayment mortgage ends at zero, not at rounding residue');
        $this->assertSame(2042, $schedule->finalPaymentYear());
        $this->assertSame(0, $schedule->paymentIn(2043)->pence, 'nothing is owed after the term ends');
        $this->assertSame(0, $schedule->openingBalanceIn(2043)->pence);
    }

    public function test_total_interest_and_total_repaid_match_the_esis(): void
    {
        $schedule = $this->esisSchedule();

        // ESIS: total interest £101,886.01, total amount to be repaid £261,886.01 (the £261,986.20
        // headline adds the £100 redemption fee, which is a fee, not part of the loan).
        $this->assertEqualsWithDelta(10_188_601, $schedule->totalInterest()->pence, 100);
        $this->assertEqualsWithDelta(26_188_601, $schedule->totalRepaid()->pence, 100);

        // Reconciliation: every pound repaid is either interest or the capital borrowed.
        $this->assertSame(
            $schedule->totalRepaid()->pence,
            $schedule->totalInterest()->pence + 16_000_000,
            'total repaid must equal capital + interest exactly',
        );
    }

    public function test_a_part_year_start_charges_only_the_instalments_that_fall_in_it(): void
    {
        $schedule = $this->esisSchedule();

        // First payment is September 2026, so 2026 carries four instalments, not twelve.
        $this->assertSame(4 * 131_854, $schedule->paymentIn(2026)->pence);
        $this->assertSame(12 * 131_854, $schedule->paymentIn(2027)->pence);

        // ...and the year opens on the full loan, before any of it is repaid.
        $this->assertSame(16_000_000, $schedule->openingBalanceIn(2026)->pence);
        $this->assertSame(15_803_326, $schedule->openingBalanceIn(2027)->pence);
    }

    public function test_the_opening_balance_falls_every_year_of_the_term(): void
    {
        $schedule = $this->esisSchedule();

        $previous = $schedule->openingBalanceIn(2026)->pence;
        for ($year = 2027; $year <= 2042; $year++) {
            $balance = $schedule->openingBalanceIn($year)->pence;
            $this->assertLessThan($previous, $balance, "the balance must fall into {$year}");
            $previous = $balance;
        }
        $this->assertSame(0, $schedule->openingBalanceIn(2043)->pence);
    }

    public function test_a_single_rate_mortgage_amortises_on_the_standard_annuity(): void
    {
        // £100,000 over 25 years at 5%: the textbook monthly payment is £584.59.
        $schedule = AmortisationSchedule::for(Money::fromPounds(100_000), new RepaymentMortgageTerms(
            termMonths: 300,
            firstPaymentYear: 2026,
            firstPaymentMonth: 1,
            ratePeriods: [new MortgageRatePeriod(Percent::fromPercent(5))],
        ));

        $this->assertSame(58_459, $schedule->rows()[0]['payment']);
        $this->assertSame(12 * 58_459, $schedule->paymentIn(2026)->pence);
        $this->assertSame(0, $schedule->rows()[299]['balance']);
        $this->assertSame(2050, $schedule->finalPaymentYear());
    }

    public function test_an_interest_free_loan_repays_capital_only(): void
    {
        $schedule = AmortisationSchedule::for(Money::fromPounds(12_000), new RepaymentMortgageTerms(
            termMonths: 12,
            firstPaymentYear: 2026,
            firstPaymentMonth: 1,
            ratePeriods: [new MortgageRatePeriod(Percent::zero())],
        ));

        $this->assertSame(0, $schedule->totalInterest()->pence);
        $this->assertSame(1_200_000, $schedule->totalRepaid()->pence);
        $this->assertSame(100_000, $schedule->rows()[0]['payment']);
        $this->assertSame(0, $schedule->rows()[11]['balance']);
    }

    public function test_interest_falls_as_the_balance_amortises(): void
    {
        $schedule = $this->esisSchedule();

        // Under the same 7.24% rate throughout, a shrinking balance must cost less interest each
        // year — the property that makes a repayment mortgage differ from an interest-only one.
        for ($year = 2033; $year < 2042; $year++) {
            $this->assertLessThan(
                $schedule->interestIn($year)->pence,
                $schedule->interestIn($year + 1)->pence,
                "interest must fall from {$year} to ".($year + 1),
            );
        }
    }

    public function test_the_terms_reject_a_closed_final_rate_period(): void
    {
        // A closed final period would leave the last 60 months of the term with no rate.
        $this->expectException(\InvalidArgumentException::class);
        new RepaymentMortgageTerms(120, 2026, 1, [new MortgageRatePeriod(Percent::fromPercent(5), 60)]);
    }

    public function test_the_terms_reject_rate_periods_longer_than_the_term(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RepaymentMortgageTerms(60, 2026, 1, [
            new MortgageRatePeriod(Percent::fromPercent(5), 60),
            new MortgageRatePeriod(Percent::fromPercent(7)),
        ]);
    }

    public function test_the_terms_reject_an_out_of_range_first_payment_month(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RepaymentMortgageTerms(60, 2026, 13, [new MortgageRatePeriod(Percent::fromPercent(5))]);
    }
}

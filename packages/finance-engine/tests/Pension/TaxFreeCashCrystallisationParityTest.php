<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Pension;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Forecast\PathProjector;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Pension\TaxFreeCashCalculator;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * The 25% rule has two implementations, on purpose: {@see TaxFreeCashCalculator::split} works in
 * Money and feeds the lump-sum tax-shock panel, {@see PathProjector::ufplsSplit} works in raw
 * integer pence inside the year loop. They drifted: the projector learned that crystallised money
 * gets no second quarter and the calculator did not, so a UFPLS taken after a lump sum on the same
 * pot was wholly taxable in the forecast and a quarter tax-free on the panel — two answers for one
 * withdrawal, out of one engine.
 *
 * This holds them to the same answer, so the next person to change either one finds out here.
 */
final class TaxFreeCashCrystallisationParityTest extends TestCase
{
    public function test_the_projector_and_the_panel_split_the_same_payment_the_same_way(): void
    {
        $config = TaxYearRegistry::for('2026-27');
        $calculator = new TaxFreeCashCalculator($config);
        $rate = $config->pension->pclsRate->asFraction();

        $allowances = [0, 1_00, 12_345_67, $config->pension->lumpSumAllowance->pence];

        foreach ([0, 1, 99, 4_000_00, 100_000_00] as $gross) {
            foreach ($allowances as $allowance) {
                foreach ([0, 1, 999_99, $gross, $gross + 1_000_00] as $crystallised) {
                    $where = "gross $gross, allowance $allowance, crystallised $crystallised";

                    [$taxFree, $taxable] = PathProjector::ufplsSplit($gross, $allowance, $rate, $crystallised);
                    $split = $calculator->split(
                        Money::fromPence($gross),
                        Money::fromPence($allowance),
                        Money::fromPence($crystallised),
                    );

                    $this->assertSame($taxFree, $split->taxFree->pence, "tax-free disagrees at $where");
                    $this->assertSame($taxable, $split->taxable->pence, "taxable disagrees at $where");
                }
            }
        }
    }

    public function test_no_crystallised_slice_is_the_ordinary_uncrystallised_pot(): void
    {
        $calculator = new TaxFreeCashCalculator(TaxYearRegistry::for('2026-27'));
        $gross = Money::fromPounds(60_000);
        $lsa = TaxYearRegistry::for('2026-27')->pension->lumpSumAllowance;

        // The new parameter defaults to "none", so every existing caller is unmoved.
        $this->assertSame(
            $calculator->split($gross, $lsa)->taxFree->pence,
            $calculator->split($gross, $lsa, Money::zero())->taxFree->pence,
        );
    }
}

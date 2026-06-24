<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tax;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;
use RetireForecast\FinanceEngine\Money\RoundingMode;
use RetireForecast\FinanceEngine\TaxYear\TaxYearConfig;

/**
 * Income tax on non-savings, non-dividend income (earnings and pension income)
 * for England, Wales and Northern Ireland.
 *
 * Savings and dividend income are taxed by their own calculators, stacking on
 * top of the bands consumed here; this class exposes {@see remainingBasicRateRoom}
 * and the granted personal allowance so those layers can be added without a
 * rewrite. State Pension is non-savings income and flows through here too.
 */
final class IncomeTaxCalculator
{
    public function __construct(private readonly TaxYearConfig $config)
    {
    }

    /**
     * The personal allowance after the £1-per-£2 taper above the taper threshold.
     * The taper is assessed on total (adjusted) income, which may exceed the
     * non-savings income being taxed once savings and dividends are layered in.
     */
    public function personalAllowance(Money $totalIncome): Money
    {
        $params = $this->config->incomeTax;

        $excess = $totalIncome->minus($params->taperThreshold)->minZero();
        $reduction = $excess->applyRate($params->taperRate, RoundingMode::Floor);

        return $params->personalAllowance->minus($reduction)->minZero();
    }

    /**
     * Tax due on non-savings income.
     *
     * @param Money      $income             the non-savings income to tax
     * @param Money|null $totalIncomeForTaper total adjusted income for the allowance
     *                                        taper; defaults to $income when there is
     *                                        no other income on top
     */
    public function onNonSavingsIncome(Money $income, ?Money $totalIncomeForTaper = null): IncomeTaxResult
    {
        $params = $this->config->incomeTax;
        $totalIncome = $totalIncomeForTaper ?? $income;

        $allowance = $this->personalAllowance($totalIncome);

        $basicLower = $allowance;
        $basicUpper = $allowance->plus($params->basicRateBand);
        $additionalThreshold = $params->additionalRateThreshold;

        $basicAmount = $this->slice($income, $basicLower, $basicUpper);
        $higherAmount = $this->slice($income, $basicUpper, $additionalThreshold);
        $additionalAmount = $this->slice($income, $additionalThreshold, null);

        $basicTax = $basicAmount->applyRate($params->basicRate);
        $higherTax = $higherAmount->applyRate($params->higherRate);
        $additionalTax = $additionalAmount->applyRate($params->additionalRate);

        $total = $basicTax->plus($higherTax)->plus($additionalTax);

        return new IncomeTaxResult(
            total: $total,
            personalAllowance: $allowance,
            bands: [
                ['rate' => $params->basicRate, 'amount' => $basicAmount, 'tax' => $basicTax],
                ['rate' => $params->higherRate, 'amount' => $higherAmount, 'tax' => $higherTax],
                ['rate' => $params->additionalRate, 'amount' => $additionalAmount, 'tax' => $additionalTax],
            ],
        );
    }

    /**
     * Full income-tax calculation across all three categories, stacked in the
     * statutory order: non-savings, then savings, then dividends.
     *
     * Handles the parts {@see onNonSavingsIncome} omits: the savings starting-rate
     * band, the Personal Savings Allowance, and the dividend allowance and dividend
     * rates. Each 0% allowance still consumes rate-band space (it pushes the income
     * above it into higher bands), which is why the bands are filled with a single
     * shared cursor rather than three independent slicings.
     */
    public function compute(TaxableIncome $income): ComprehensiveIncomeTaxResult
    {
        $params = $this->config->incomeTax;
        $savings = $this->config->savings;
        $dividends = $this->config->dividends;

        $total = $income->total();
        $allowance = $this->personalAllowance($total);

        // Personal allowance is set against income in the statutory order.
        [$nsTaxable, $allowanceLeft] = $this->afterAllowance($income->nonSavings, $allowance);
        [$savingsTaxable, $allowanceLeft] = $this->afterAllowance($income->savings, $allowanceLeft);
        [$dividendsTaxable] = $this->afterAllowance($income->dividends, $allowanceLeft);

        // Rate-band capacities in taxable space (income above the personal allowance).
        // The basic band has a fixed width; the higher band runs up to the fixed
        // additional-rate threshold; the additional band is unbounded.
        $higherCap = $params->additionalRateThreshold
            ->minus($allowance)
            ->minus($params->basicRateBand)
            ->minZero();

        $bands = [
            ['name' => 'basic', 'remaining' => $params->basicRateBand->pence],
            ['name' => 'higher', 'remaining' => $higherCap->pence],
            ['name' => 'additional', 'remaining' => PHP_INT_MAX],
        ];

        // Fill bands from the bottom; returns the slices a given amount occupies.
        $consume = function (int $pence) use (&$bands): array {
            $parts = [];
            foreach ($bands as &$band) {
                if ($pence <= 0) {
                    break;
                }
                if ($band['remaining'] <= 0) {
                    continue;
                }
                $take = min($pence, $band['remaining']);
                $band['remaining'] -= $take;
                $pence -= $take;
                $parts[] = ['name' => $band['name'], 'pence' => $take];
            }

            return $parts;
        };

        // Which marginal band the whole liability reaches, for the PSA size.
        $totalTaxablePence = $nsTaxable->pence + $savingsTaxable->pence + $dividendsTaxable->pence;
        $basicCeiling = $params->basicRateBand->pence;
        $higherCeiling = $basicCeiling + $higherCap->pence;
        $psa = match (true) {
            $totalTaxablePence > $higherCeiling => $savings->psaAdditionalRate,
            $totalTaxablePence > $basicCeiling => $savings->psaHigherRate,
            default => $savings->psaBasicRate,
        };

        $lines = [];

        // Non-savings: each band slice taxed at the corresponding main rate.
        $nonSavingsRate = fn (string $band): Percent => match ($band) {
            'basic' => $params->basicRate,
            'higher' => $params->higherRate,
            'additional' => $params->additionalRate,
        };
        $nonSavingsTax = $this->taxParts(
            $consume($nsTaxable->pence),
            'non_savings',
            $nonSavingsRate,
            0,
            $lines,
        );

        // Savings: the starting-rate band (reduced £1-for-£1 by non-savings taxable
        // income) plus the PSA are charged at 0% on the lowest savings first.
        $startingRateRoom = $savings->startingRateBand->pence - $nsTaxable->pence;
        $savingsZeroBudget = max(0, $startingRateRoom) + $psa->pence;
        $savingsTax = $this->taxParts(
            $consume($savingsTaxable->pence),
            'savings',
            $nonSavingsRate,
            $savingsZeroBudget,
            $lines,
        );

        // Dividends: the dividend allowance is charged at 0% on the lowest dividends
        // first; the rest at the dividend rate for its band.
        $dividendRate = fn (string $band): Percent => match ($band) {
            'basic' => $dividends->ordinaryRate,
            'higher' => $dividends->upperRate,
            'additional' => $dividends->additionalRate,
        };
        $dividendsTax = $this->taxParts(
            $consume($dividendsTaxable->pence),
            'dividends',
            $dividendRate,
            $dividends->allowance->pence,
            $lines,
        );

        return new ComprehensiveIncomeTaxResult(
            total: $nonSavingsTax->plus($savingsTax)->plus($dividendsTax),
            personalAllowance: $allowance,
            nonSavingsTax: $nonSavingsTax,
            savingsTax: $savingsTax,
            dividendsTax: $dividendsTax,
            lines: $lines,
        );
    }

    /**
     * Tax a list of band slices for one income type, charging the first
     * $zeroBudget pence at 0% (a 0% allowance that still consumed band space) and
     * the remainder at the rate the band maps to. Appends to $lines by reference
     * and returns the total tax for this income type.
     *
     * @param list<array{name: string, pence: int}> $parts
     * @param callable(string): Percent              $rateFor
     * @param list<array{type: string, band: string, rate: Percent, amount: Money, tax: Money}> $lines
     */
    private function taxParts(array $parts, string $type, callable $rateFor, int $zeroBudget, array &$lines): Money
    {
        $tax = Money::zero();

        foreach ($parts as $part) {
            $amount = $part['pence'];

            $zeroHere = min($zeroBudget, $amount);
            $zeroBudget -= $zeroHere;
            $taxedHere = $amount - $zeroHere;

            if ($zeroHere > 0) {
                $lines[] = [
                    'type' => $type,
                    'band' => $type === 'savings' ? 'allowance' : ($type === 'dividends' ? 'allowance' : $part['name']),
                    'rate' => Percent::zero(),
                    'amount' => Money::fromPence($zeroHere),
                    'tax' => Money::zero(),
                ];
            }

            if ($taxedHere > 0) {
                $rate = $rateFor($part['name']);
                $sliceTax = Money::fromPence($taxedHere)->applyRate($rate);
                $tax = $tax->plus($sliceTax);
                $lines[] = [
                    'type' => $type,
                    'band' => $part['name'],
                    'rate' => $rate,
                    'amount' => Money::fromPence($taxedHere),
                    'tax' => $sliceTax,
                ];
            }
        }

        return $tax;
    }

    /**
     * Split $amount into the part covered by $allowance (tax-free) and the
     * remainder, returning [taxable remainder, allowance left over].
     *
     * @return array{0: Money, 1: Money}
     */
    private function afterAllowance(Money $amount, Money $allowance): array
    {
        $used = Money::min($amount, $allowance);

        return [$amount->minus($used), $allowance->minus($used)];
    }

    /**
     * The slice of $income that falls between $lower and $upper (null = no upper
     * bound), never negative.
     */
    private function slice(Money $income, Money $lower, ?Money $upper): Money
    {
        $top = $upper === null ? $income : Money::min($income, $upper);

        return $top->minus($lower)->minZero();
    }
}

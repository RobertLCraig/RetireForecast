<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tax;

use RetireForecast\FinanceEngine\Money\IntMath;
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
    public function __construct(private readonly TaxYearConfig $config) {}

    /**
     * The personal allowance after the £1-per-£2 taper above the taper threshold.
     * The taper is assessed on total (adjusted) income, which may exceed the
     * non-savings income being taxed once savings and dividends are layered in.
     */
    public function personalAllowance(Money $totalIncome): Money
    {
        return Money::fromPence($this->grantedAllowancePence($totalIncome->pence));
    }

    /**
     * The granted personal allowance, in pence, after the £1-per-£2 taper. The
     * integer home of {@see personalAllowance}, so the Money wrapper and the hot
     * integer path ({@see totalPence}) taper identically.
     */
    private function grantedAllowancePence(int $totalIncomePence): int
    {
        $params = $this->config->incomeTax;

        $excess = max(0, $totalIncomePence - $params->taperThreshold->pence);
        $reduction = IntMath::divRound($excess * $params->taperRate->basisPoints, 10_000, RoundingMode::Floor);

        return max(0, $params->personalAllowance->pence - $reduction);
    }

    /**
     * Tax due on non-savings income.
     *
     * @param  Money  $income  the non-savings income to tax
     * @param  Money|null  $totalIncomeForTaper  total adjusted income for the allowance
     *                                           taper; defaults to $income when there is
     *                                           no other income on top
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
        $core = $this->bandedTax($income, withLines: true);

        // Decorate the integer core as Money + the itemised lines the UI needs,
        // in the statutory order (non-savings, then savings, then dividends).
        $lines = [];
        foreach (['nonSavings', 'savings', 'dividends'] as $key) {
            foreach ($core[$key]['lines'] as $line) {
                $lines[] = [
                    'type' => $line['type'],
                    'band' => $line['band'],
                    'rate' => $line['rate'],
                    'amount' => Money::fromPence($line['amount']),
                    'tax' => Money::fromPence($line['tax']),
                ];
            }
        }

        return new ComprehensiveIncomeTaxResult(
            total: Money::fromPence($core['total']),
            personalAllowance: Money::fromPence($core['allowance']),
            nonSavingsTax: Money::fromPence($core['nonSavings']['tax']),
            savingsTax: Money::fromPence($core['savings']['tax']),
            dividendsTax: Money::fromPence($core['dividends']['tax']),
            lines: $lines,
        );
    }

    /**
     * Total income tax due, in pence, for the full stacked computation. The lean
     * integer twin of {@see compute}: it shares the exact same band core but skips
     * the Money and per-line breakdown that callers needing only the total discard.
     * The hot Monte Carlo path ({@see PathProjector}) runs this millions of times,
     * so the saved allocation is the point. An equivalence test pins
     * totalPence($i) === compute($i)->total->pence across every band crossing.
     */
    public function totalPence(TaxableIncome $income): int
    {
        return $this->bandedTax($income, withLines: false)['total'];
    }

    /**
     * The shared integer core of the full income-tax computation: splits each
     * income category across the rate bands in the statutory order (non-savings,
     * then savings, then dividends, sharing one band cursor so each 0% allowance
     * still consumes band space) and charges each slice in pure pence. One
     * computation, two presentations — {@see compute} wraps it as Money/lines,
     * {@see totalPence} reads the total. Lines are built only when $withLines.
     *
     * @return array{
     *     total: int,
     *     allowance: int,
     *     nonSavings: array{tax: int, lines: list<array{type: string, band: string, rate: Percent, amount: int, tax: int}>},
     *     savings: array{tax: int, lines: list<array{type: string, band: string, rate: Percent, amount: int, tax: int}>},
     *     dividends: array{tax: int, lines: list<array{type: string, band: string, rate: Percent, amount: int, tax: int}>},
     * }
     */
    private function bandedTax(TaxableIncome $income, bool $withLines): array
    {
        $params = $this->config->incomeTax;
        $savings = $this->config->savings;
        $dividends = $this->config->dividends;

        $allowance = $this->grantedAllowancePence($income->total()->pence);

        // Personal allowance set against income in the statutory order.
        $usedNs = min($income->nonSavings->pence, $allowance);
        $nsTaxable = $income->nonSavings->pence - $usedNs;
        $allowanceLeft = $allowance - $usedNs;
        $usedSavings = min($income->savings->pence, $allowanceLeft);
        $savingsTaxable = $income->savings->pence - $usedSavings;
        $allowanceLeft -= $usedSavings;
        $dividendsTaxable = $income->dividends->pence - min($income->dividends->pence, $allowanceLeft);

        // Rate-band capacities in taxable space (income above the personal allowance).
        // The basic band has a fixed width; the higher band runs up to the fixed
        // additional-rate threshold; the additional band is unbounded.
        $higherCap = max(0, $params->additionalRateThreshold->pence - $allowance - $params->basicRateBand->pence);

        $bands = [
            ['name' => 'basic', 'remaining' => $params->basicRateBand->pence],
            ['name' => 'higher', 'remaining' => $higherCap],
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
        $totalTaxablePence = $nsTaxable + $savingsTaxable + $dividendsTaxable;
        $basicCeiling = $params->basicRateBand->pence;
        $higherCeiling = $basicCeiling + $higherCap;
        $psaPence = match (true) {
            $totalTaxablePence > $higherCeiling => $savings->psaAdditionalRate->pence,
            $totalTaxablePence > $basicCeiling => $savings->psaHigherRate->pence,
            default => $savings->psaBasicRate->pence,
        };

        // Non-savings: each band slice taxed at the corresponding main rate.
        $nonSavingsRate = fn (string $band): Percent => match ($band) {
            'basic' => $params->basicRate,
            'higher' => $params->higherRate,
            'additional' => $params->additionalRate,
        };

        // Dividends: each band slice taxed at the dividend rate for its band.
        $dividendRate = fn (string $band): Percent => match ($band) {
            'basic' => $dividends->ordinaryRate,
            'higher' => $dividends->upperRate,
            'additional' => $dividends->additionalRate,
        };

        // Savings: the starting-rate band (reduced £1-for-£1 by non-savings taxable
        // income) plus the PSA are charged at 0% on the lowest savings first.
        $savingsZeroBudget = max(0, $savings->startingRateBand->pence - $nsTaxable) + $psaPence;

        $ns = $this->chargeParts($consume($nsTaxable), 'non_savings', $nonSavingsRate, 0, $withLines);
        $sav = $this->chargeParts($consume($savingsTaxable), 'savings', $nonSavingsRate, $savingsZeroBudget, $withLines);
        $div = $this->chargeParts($consume($dividendsTaxable), 'dividends', $dividendRate, $dividends->allowance->pence, $withLines);

        return [
            'total' => $ns['tax'] + $sav['tax'] + $div['tax'],
            'allowance' => $allowance,
            'nonSavings' => $ns,
            'savings' => $sav,
            'dividends' => $div,
        ];
    }

    /**
     * Charge a list of band slices for one income type in pure pence: the first
     * $zeroBudget pence at 0% (a 0% allowance that still consumed band space), the
     * remainder at the rate its band maps to. Returns this type's total tax and,
     * when $withLines, the itemised lines (integer amounts; {@see compute} wraps
     * them as Money). The per-slice rounding mirrors {@see Money::applyRate}
     * exactly, so the integer total equals the Money sum to the penny.
     *
     * @param  list<array{name: string, pence: int}>  $parts
     * @param  callable(string): Percent  $rateFor
     * @return array{tax: int, lines: list<array{type: string, band: string, rate: Percent, amount: int, tax: int}>}
     */
    private function chargeParts(array $parts, string $type, callable $rateFor, int $zeroBudget, bool $withLines): array
    {
        $tax = 0;
        $lines = [];

        foreach ($parts as $part) {
            $amount = $part['pence'];

            $zeroHere = min($zeroBudget, $amount);
            $zeroBudget -= $zeroHere;
            $taxedHere = $amount - $zeroHere;

            if ($withLines && $zeroHere > 0) {
                $lines[] = [
                    'type' => $type,
                    'band' => $type === 'non_savings' ? $part['name'] : 'allowance',
                    'rate' => Percent::zero(),
                    'amount' => $zeroHere,
                    'tax' => 0,
                ];
            }

            if ($taxedHere > 0) {
                $rate = $rateFor($part['name']);
                $sliceTax = IntMath::divRound($taxedHere * $rate->basisPoints, 10_000, RoundingMode::HalfUp);
                $tax += $sliceTax;
                if ($withLines) {
                    $lines[] = [
                        'type' => $type,
                        'band' => $part['name'],
                        'rate' => $rate,
                        'amount' => $taxedHere,
                        'tax' => $sliceTax,
                    ];
                }
            }
        }

        return ['tax' => $tax, 'lines' => $lines];
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

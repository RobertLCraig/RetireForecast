<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;

/**
 * The tax split on a PURCHASED LIFE ANNUITY — one bought with money that is not pension money
 * (cash, an ISA, a general investment account). Board card 0060.
 *
 * Each payment is part return of the buyer's own capital and part interest. Only the interest
 * element is taxable income; the capital element is exempt (ITTOIA 2005 Part 6 Chapter 7). The
 * capital element is the purchase price spread evenly over the buyer's expected remaining life
 * at the age the income starts, and it is a FIXED sum for the life of the annuity — so an
 * escalating annuity's exempt PROPORTION shrinks as its payments grow, which is the real rule.
 *
 * The statutory figure is the expectation of life from the tables prescribed by the Income Tax
 * (Purchased Life Annuities) Regulations. This engine holds no copy of those tables, so it uses
 * its own ONS-based cohort life expectancy instead ({@see CohortLifeTable}):
 * a real, sourced figure rather than an invented one, but NOT the prescribed table, so the exempt
 * proportion here is close to but not identical with HMRC's. STATED, not verified — see
 * docs/spec/ASSUMPTIONS.md §32, and board card 0136 for sourcing the prescribed table.
 */
final class PurchasedLifeAnnuity
{
    /**
     * The exempt capital element of ONE year's annuity payment: the purchase price divided by the
     * expected remaining years of life, capped at the payment itself (an annuity cannot return more
     * capital in a year than it pays out, and a nil or negative expectancy exempts nothing).
     */
    public static function capitalElementPerYear(Money $purchasePrice, float $lifeExpectancyYears, Money $annualIncome): Money
    {
        if ($lifeExpectancyYears <= 0.0 || ! $purchasePrice->isPositive()) {
            return Money::zero();
        }

        return Money::fromPence(min(
            (int) round($purchasePrice->pence / $lifeExpectancyYears),
            $annualIncome->pence,
        ));
    }
}

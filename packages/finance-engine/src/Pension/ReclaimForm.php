<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

/**
 * The HMRC form used to reclaim tax over-deducted under the emergency (Month-1)
 * basis on a first flexible pension withdrawal.
 *
 *  - P55:  you have taken only part of your pot (not emptied it) and are not taking
 *          regular payments, so the provider cannot refund you within the year.
 *  - P50Z: you have emptied your pot AND have no other taxable income.
 *  - P53Z: you have emptied your pot AND still have other taxable income.
 *
 * If regular taxable payments continue, the tax code normally catches up over the
 * year and no standalone reclaim is needed; these forms are for the one-off case.
 */
enum ReclaimForm: string
{
    case P55 = 'P55';
    case P50Z = 'P50Z';
    case P53Z = 'P53Z';

    public static function determine(bool $potEmptied, bool $hasOtherTaxableIncome): self
    {
        if (! $potEmptied) {
            return self::P55;
        }

        return $hasOtherTaxableIncome ? self::P53Z : self::P50Z;
    }
}

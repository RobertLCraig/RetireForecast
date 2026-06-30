<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Housing;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * One line of the cost of selling a home (estate agent, legal/conveyancing, EPC &
 * removals, ...). Each line is entered on whichever basis the real-world quote uses:
 * a {@see Percent} of the sale price (how agents usually quote) OR a flat {@see Money}
 * fee (how conveyancing usually quotes). The basis IS the value's type, so a line can
 * never be ambiguous about which it is.
 *
 * {@see amount()} resolves the line to pounds against a given sale price; the sum of a
 * sale's components is its total selling cost ({@see HousingProceeds::$sellingCosts}),
 * reconciled there.
 */
final class SellingCostComponent
{
    public function __construct(
        public readonly string $label,
        public readonly Percent|Money $value,
    ) {}

    /** The £ this line costs on the given sale price: a rate applied to it, or a flat fee. */
    public function amount(Money $salePrice): Money
    {
        return $this->value instanceof Percent
            ? $salePrice->applyRate($this->value)
            : $this->value;
    }
}

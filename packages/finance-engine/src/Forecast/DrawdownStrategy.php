<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Forecast;

/**
 * How the household funds a spending shortfall. Both strategies ship and are
 * compared side by side (see DECISIONS.md); tax-efficient is the default display.
 *
 *  - TaxEfficient: spend non-pension assets first (cash, then GIA, then ISA) and
 *    leave the pension to grow, drawing it only as a last resort.
 *  - PensionAware: draw taxable pension income earlier (capped at the basic-rate
 *    band to avoid a tax spike) before non-pension assets, to run the pot down
 *    ahead of unused pots entering the IHT estate from April 2027.
 */
enum DrawdownStrategy
{
    case TaxEfficient;
    case PensionAware;
}

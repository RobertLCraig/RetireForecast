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
 *  - FillBands: "fill the band" - fill each tax-free allowance before a taxed
 *    pound: pension within the personal allowance (0% tax), then GIA gains within
 *    the CGT annual exempt amount (0% CGT), then cash + ISA (tax-free capital),
 *    then pension within the basic-rate band, then the rest. Pension-Credit-aware:
 *    a household on Guarantee Credit draws capital first and leaves the pension
 *    (and the credit) intact, since any pension income claws the credit back
 *    £-for-£. See docs/PLAN-withdrawal-sequencing.md.
 */
enum DrawdownStrategy
{
    case TaxEfficient;
    case PensionAware;
    case FillBands;
}

<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

use RetireForecast\FinanceEngine\Money\Percent;

/**
 * The rates property income (rent, less its allowable costs) is taxed at in one tax year.
 *
 * Until 5 April 2027 property income is taxed at the main rates. From 6 April 2027 it has its own
 * rates in England, Wales and Northern Ireland (Budget 2025), stacked after the other non-savings
 * income and before savings and dividends. Finance costs (mortgage interest) are relieved as a
 * credit at the property basic rate.
 */
final class PropertyIncomeParameters
{
    public function __construct(
        public readonly Percent $basicRate,
        public readonly Percent $higherRate,
        public readonly Percent $additionalRate,
    ) {}
}

<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\TaxYear;

/**
 * UK tax region for income-tax band resolution.
 *
 * England, Wales and Northern Ireland share one income-tax band structure.
 * Scotland sets its own non-savings, non-dividend bands (savings, dividends, NI
 * and pensions stay UK-wide). The registry deliberately refuses to build a
 * Scottish config until the Scottish band pack is loaded, rather than silently
 * applying the rest-of-UK bands.
 */
enum RegionProfile: string
{
    case EnglandWalesNi = 'england_wales_ni';
    case Scotland = 'scotland';
}

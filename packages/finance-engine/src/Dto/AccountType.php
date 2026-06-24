<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

/**
 * A non-pension savings/investment account type. The tax and benefit treatment
 * differs: ISA income is tax-free, GIA holdings can have taxable interest,
 * dividends and capital gains, cash earns taxable interest. All count as
 * assessable capital for means-tested benefits (unlike the main home).
 */
enum AccountType: string
{
    case Isa = 'isa';
    case Gia = 'gia';
    case Cash = 'cash';
    case PremiumBonds = 'premium_bonds';
}

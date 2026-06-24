<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Pension;

/**
 * The kind of pension withdrawal being made, which determines its tax treatment
 * and whether it triggers the Money Purchase Annual Allowance.
 */
enum WithdrawalKind
{
    /**
     * Pension Commencement Lump Sum: the tax-free cash (25%, subject to the Lump
     * Sum Allowance) taken on crystallisation. Does NOT trigger the MPAA on its own.
     */
    case Pcls;

    /**
     * Uncrystallised Funds Pension Lump Sum: each withdrawal is 25% tax-free and
     * 75% taxable as income. Triggers the MPAA.
     */
    case Ufpls;

    /**
     * Taxable income drawn from flexi-access drawdown. Fully taxable. Triggers the
     * MPAA. (Taking only the tax-free cash and moving the rest into drawdown does
     * not; it is drawing taxable income that does.)
     */
    case DrawdownIncome;

    /** Whether making this kind of withdrawal triggers the Money Purchase Annual Allowance. */
    public function triggersMpaa(): bool
    {
        return match ($this) {
            self::Pcls => false,
            self::Ufpls, self::DrawdownIncome => true,
        };
    }
}

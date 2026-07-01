<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A plan to buy a lifetime annuity from a DC pension pot: at $atAge, $amount of the pot
 * is used to buy an annuity paying $amount × $rate a year, for life.
 *
 * The pot falls by the purchase amount (it becomes an income, not a capital sum — like a
 * DB pension, it holds no drawable value afterwards), and the income is taxed as it is
 * received, so buying the annuity is not itself a taxable event.
 *
 * $escalation === None is a LEVEL annuity: a flat nominal income that falls in real terms.
 * Any other basis escalates the income with inflation from purchase (an RPI/CPI annuity,
 * roughly flat in real terms), mirroring how the engine escalates DB pensions in payment.
 *
 * $survivorFraction (null = single life) is the fraction of the income that continues to
 * the surviving partner after the annuitant dies — a joint-life annuity.
 *
 * $rate is a user input (defaulted from a sourced market quote in the UI), so no fabricated
 * age/rate table is baked into the engine — the engine only multiplies the pot by the rate.
 */
final class AnnuityPurchase
{
    public function __construct(
        public readonly int $atAge,
        public readonly Money $amount,
        public readonly Percent $rate,
        public readonly PensionEscalationBasis $escalation = PensionEscalationBasis::None,
        public readonly ?Percent $survivorFraction = null,
    ) {}
}

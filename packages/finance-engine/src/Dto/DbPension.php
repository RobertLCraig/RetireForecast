<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * A Defined Benefit (final-salary) pension: a guaranteed annual income from the
 * normal retirement age, revalued before payment and escalated in payment, with an
 * optional survivor's fraction that matters for the joint-life model.
 *
 * An optional tax-free commutation lump sum can be taken at retirement in exchange
 * for giving up some annual income (at the scheme's commutation factor).
 */
final class DbPension implements Pension
{
    public function __construct(
        public readonly string $ownerId,
        public readonly Money $accruedAnnualPension,
        public readonly int $normalRetirementAge,
        public readonly PensionEscalationBasis $revaluationBasis = PensionEscalationBasis::Cpi,
        public readonly PensionEscalationBasis $escalationInPayment = PensionEscalationBasis::Cpi,
        public readonly ?Percent $spousePensionFraction = null,
        public readonly ?Money $commutationLumpSum = null,
        public readonly ?Percent $commutationFactor = null,
    ) {}

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function type(): PensionType
    {
        return PensionType::DefinedBenefit;
    }
}

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
 * for giving up some annual income: the annual pension is permanently reduced by
 * $commutationLumpSum ÷ $commutationFactor (the scheme's £-lump-sum-per-£1-pension-given-up
 * ratio, e.g. 12 for a common 12:1 scheme), and the lump sum is paid tax-free at
 * retirement. A null/zero factor defaults to 12.
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
        /** £ of tax-free lump sum per £1/yr of pension given up (e.g. 12 for 12:1); null/≤0 defaults to 12. */
        public readonly ?float $commutationFactor = null,
    ) {}

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function type(): PensionType
    {
        return PensionType::DefinedBenefit;
    }

    /**
     * The same scheme with a different survivor's fraction (immutable; e.g. a sweep lever exploring
     * how much survivor provision the money needs). Everything else is preserved.
     */
    public function withSpousePensionFraction(?Percent $spousePensionFraction): self
    {
        return new self(
            $this->ownerId,
            $this->accruedAnnualPension,
            $this->normalRetirementAge,
            $this->revaluationBasis,
            $this->escalationInPayment,
            $spousePensionFraction,
            $this->commutationLumpSum,
            $this->commutationFactor,
        );
    }
}

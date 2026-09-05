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
 *
 * $revaluationBasis governs the DEFERRED years (accrual to normal retirement age) and
 * $escalationInPayment the years after it; the two are separate rules and the projector
 * switches between them at normal retirement age. $fixedEscalationRate is the rate a
 * PensionEscalationBasis::Fixed scheme grants; null takes {@see DEFAULT_FIXED_ESCALATION_BPS},
 * which is disclosed rather than assumed silently ({@see fixedEscalationIsAssumed}).
 */
final class DbPension implements Pension
{
    /**
     * The fixed annual increase used when a scheme is set to PensionEscalationBasis::Fixed and
     * the reader entered no rate of their own: 3% a year.
     *
     * SOURCE: judgement, not a published series. 3% and 5% are the two rates UK scheme rules
     * commonly grant, and 3% is the lower — the adverse reading for income the household
     * receives, which is the house rule where several figures are plausible. The gap is board
     * card 0095. Read, never restated, by the disclosure that surfaces it, and overridden
     * outright by a rate the reader enters.
     */
    public const DEFAULT_FIXED_ESCALATION_BPS = 300;

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
        /** The increase a Fixed-basis scheme grants each year; null takes the disclosed default. */
        public readonly ?Percent $fixedEscalationRate = null,
    ) {}

    /** The fixed annual increase this scheme grants: the reader's rate, else the disclosed default. */
    public function fixedEscalationRate(): Percent
    {
        return $this->fixedEscalationRate ?? Percent::fromBasisPoints(self::DEFAULT_FIXED_ESCALATION_BPS);
    }

    /**
     * Is the fixed rate in play a figure the ENGINE supplied? True only when a Fixed basis is
     * actually used and the reader gave no rate — the condition the no-invisible-figures
     * disclosure is gated on.
     */
    public function fixedEscalationIsAssumed(): bool
    {
        return $this->fixedEscalationRate === null && (
            $this->revaluationBasis === PensionEscalationBasis::Fixed
            || $this->escalationInPayment === PensionEscalationBasis::Fixed
        );
    }

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
            $this->fixedEscalationRate,
        );
    }
}

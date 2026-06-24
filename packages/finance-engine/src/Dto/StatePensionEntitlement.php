<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use RetireForecast\FinanceEngine\Money\Money;

/**
 * A person's State Pension entitlement: either a forecast weekly amount (from a
 * State Pension statement) or a count of NI qualifying years to estimate it from,
 * plus any weeks of deferral. State Pension age itself is computed from the
 * person's date of birth, not stored here.
 *
 * Provide exactly one of $weeklyForecast or $qualifyingYears.
 */
final class StatePensionEntitlement implements Pension
{
    public function __construct(
        public readonly string $ownerId,
        public readonly ?Money $weeklyForecast = null,
        public readonly ?int $qualifyingYears = null,
        public readonly int $deferralWeeks = 0,
    ) {}

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function type(): PensionType
    {
        return PensionType::State;
    }
}

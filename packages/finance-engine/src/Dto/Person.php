<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Dto;

use DateTimeImmutable;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Money\Percent;

/**
 * One member of the household. Age is never stored: it is derived from {@see $dob}
 * against the forecast's reference date, so the same person ages consistently
 * across every year of a projection.
 *
 * $id is a stable within-household reference (e.g. "p1") that pensions, accounts
 * and income streams point back to.
 */
final class Person
{
    public function __construct(
        public readonly string $id,
        public readonly DateTimeImmutable $dob,
        public readonly Sex $sex,
        public readonly EmploymentStatus $employmentStatus,
        public readonly ?Money $grossSalary = null,
        public readonly ?Percent $salaryGrowth = null,
        public readonly ?int $plannedRetirementAge = null,
        public readonly ?string $niCategory = null,
        /** Display label only (e.g. "Alex"); never used in any calculation. */
        public readonly ?string $name = null,
    ) {}
}

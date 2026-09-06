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
        /** Optional lifespan what-if; null = cohort-table peer average. */
        public readonly ?LongevityAdjustment $longevity = null,
        /**
         * Whether this person receives a qualifying disability benefit (DLA / Attendance
         * Allowance / PIP). The benefit income itself is entered as a tax-free
         * {@see IncomeStream}; this flag drives the means-tested-benefit treatment that the
         * income amount cannot convey — the Pension Credit severe-disability addition while
         * they are alive, and (the passport to) higher entitlement. Default false.
         */
        public readonly bool $receivesDisabilityBenefit = false,
        /**
         * Whether this person provides 35+ hours a week of care to a partner who receives a
         * qualifying disability benefit, giving them (underlying) entitlement to Carer's
         * Allowance and so the Pension Credit carer addition while both are alive. Underlying
         * entitlement does NOT reduce the cared-for partner's disability benefit or their
         * severe-disability addition — only *paid* Carer's Allowance would. Default false
         * (the cautious assumption: it is claimed, not assumed). See DECISIONS 2026-07-29.
         */
        public readonly bool $caresForPartner = false,
        /**
         * Employer death-in-service (group life) cover: a lump sum paid to the survivor if this
         * person dies while still in employment. Null (the default, and the adverse assumption)
         * = no cover, byte-identical to a projection that never knew about it. The cover CEASES
         * when employment does, which is the whole point of modelling it — see
         * {@see DeathInServiceCover}.
         */
        public readonly ?DeathInServiceCover $deathInServiceCover = null,
        /**
         * The age from which {@see $receivesDisabilityBenefit} applies. Null (the default) means
         * "for the whole projection", which is how every scenario saved before this field existed
         * behaved. A figure lets the commonest later-life event be modelled: a person claiming
         * Attendance Allowance once their own health declines, years into the plan. The flag alone
         * could only say on or off for life, so the years before the claim were being credited with
         * a Pension Credit addition nobody was yet entitled to.
         */
        public readonly ?int $disabilityBenefitFromAge = null,
    ) {}

    /**
     * Whether the qualifying disability benefit is in payment at $age. It is the ONE place the
     * flag and its start age are read together, so no caller can consult one without the other.
     */
    public function receivesDisabilityBenefitAt(int $age): bool
    {
        return $this->receivesDisabilityBenefit && $age >= ($this->disabilityBenefitFromAge ?? 0);
    }

    /** The same person with a different planned retirement age (immutable; e.g. a sweep lever). */
    public function withPlannedRetirementAge(?int $plannedRetirementAge): self
    {
        return new self(
            $this->id,
            $this->dob,
            $this->sex,
            $this->employmentStatus,
            $this->grossSalary,
            $this->salaryGrowth,
            $plannedRetirementAge,
            $this->niCategory,
            $this->name,
            $this->longevity,
            $this->receivesDisabilityBenefit,
            $this->caresForPartner,
            $this->deathInServiceCover,
            $this->disabilityBenefitFromAge,
        );
    }

    /** The same person with a different lifespan what-if (immutable; e.g. a per-person longevity sweep). */
    public function withLongevity(?LongevityAdjustment $longevity): self
    {
        return new self(
            $this->id,
            $this->dob,
            $this->sex,
            $this->employmentStatus,
            $this->grossSalary,
            $this->salaryGrowth,
            $this->plannedRetirementAge,
            $this->niCategory,
            $this->name,
            $longevity,
            $this->receivesDisabilityBenefit,
            $this->caresForPartner,
            $this->deathInServiceCover,
            $this->disabilityBenefitFromAge,
        );
    }
}

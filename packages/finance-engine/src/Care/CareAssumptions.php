<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Care;

use RetireForecast\FinanceEngine\Dto\Sex;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * Sourced assumptions for the stochastic late-life care-cost risk modelled in the Monte Carlo:
 * how likely a person is to need residential/nursing care, how long it typically lasts, and the
 * self-funder weekly fee. Every figure is a modelling assumption carrying its source; the numbers
 * are deliberately conservative and clearly flagged, because care is a fat right tail (most pay
 * nothing, a minority face very large bills), so a single "expected" figure would mislead — the
 * point is to show that tail in the distribution.
 *
 * Sources (verified_on 2026-07-18):
 *  - probabilityOfCare, sex-differentiated (male 0.20, female 0.30 — see {@see probabilityOfCare()}).
 *    The population mean is anchored to the Dilnot Commission / PSSRU estimate that around a quarter
 *    of people aged 65 will need residential or nursing care in later life (~1 in 4). Women's
 *    lifetime chance of entering a care home is consistently well above men's — they live longer
 *    and more often outlive a co-resident carer: NHS Digital (Health Survey for England 2021) puts
 *    "needs help with ≥1 daily task" at 28% of women vs 24% of men aged 65+; US lifetime nursing-home
 *    use runs higher still and wider (NEJM 1991 Kemper & Murtaugh ~38% women vs ~21% men; HHS ASPE
 *    lifetime paid LTSS ~55% vs ~38%). A conservative ~1.5:1 female:male ratio, calibrated to keep
 *    the ~1 in 4 population mean at an even sex split (0.20 + 0.30 averaging 0.25). Age-conditioning
 *    of the onset rate remains a flagged refinement (timing is already end-of-life anchored below).
 *  - duration (mean ~2.5 yr, right-skewed): PSSRU/LSE "Length of stay in care homes" (dp2769) —
 *    median stay ~19.6 months, mean ~29.7 months, with 72% having died within 42 months; modelled
 *    as an exponential with this mean, floored at 1 year and capped, on an annual grid. Modelled
 *    sex-blind (women's stays run somewhat longer — a flagged refinement).
 *  - weekly fees (self-funder, LaingBuisson "Care of Older People" / Care Homes for Older People
 *    UK Market Report, 35th ed., 2025): residential ~£1,300/wk, nursing ~£1,600/wk. Regional
 *    variation (London/SE +20-35%) is not modelled.
 *
 * v1 simplifications (flagged): the modelled care spell is the final $duration years of life
 * (care need concentrates near death — the engine's cohort deathAge drives timing, rather than
 * ONS health-state life expectancy, a refinement). The sampled fee is the GROSS self-funder
 * cost; the projector then means-tests each care year ({@see CareMeansTest::annualCharge()}),
 * so once the resident's own assets fall to the capital limit the household bears only the
 * income-based contribution and the local authority the balance.
 */
final class CareAssumptions
{
    public const WEEKS_PER_YEAR = 52;

    /**
     * **NHS-funded Nursing Care (FNC): £254.06 a week**, paid by the NHS DIRECT to the nursing
     * home for any resident assessed as needing care from a registered nurse, INCLUDING a
     * self-funder, and taken off the fee the resident is charged.
     *
     * It is not means-tested and it is not a benefit the household claims: it follows the nursing
     * assessment. The engine charged the whole gross nursing fee for every year of a nursing
     * spell, which overstates a nursing placement by this figure every week it runs, and a nursing
     * spell is the fat right tail the care model exists to show.
     *
     * It applies to NURSING care only ({@see nursingAnnual}). A residential placement has no
     * registered nurse to fund, so its fee stands whole.
     *
     * The rate is held FROZEN in today's money rather than uprated, which is the cautious
     * direction: a contribution that does not rise leaves MORE of the fee with the household.
     *
     * source: NHS England, "NHS-funded nursing care", standard rate for 2025/26,
     * https://www.england.nhs.uk/healthcare-funding/nhs-funded-nursing-care/
     * verified_on: NOT VERIFIED. This build had no web access, so the rate and the year it belongs
     * to are STATED, not checked against a live page. See docs/spec/ASSUMPTIONS.md §31 and board
     * card 0135.
     */
    public const FUNDED_NURSING_CARE_WEEKLY_PENCE = 254_06;

    public function __construct(
        public readonly float $probabilityOfCareMale,
        public readonly float $probabilityOfCareFemale,
        public readonly float $meanDurationYears,
        public readonly int $maxDurationYears,
        public readonly float $probabilityNursing,
        public readonly Money $residentialWeekly,
        public readonly Money $nursingWeekly,
        public readonly ?Money $fundedNursingCareWeekly = null,
    ) {}

    /** The NHS contribution to a nursing fee: the caller's own figure, else the shipped rate. */
    public function fundedNursingCareWeekly(): Money
    {
        return $this->fundedNursingCareWeekly ?? Money::fromPence(self::FUNDED_NURSING_CARE_WEEKLY_PENCE);
    }

    public static function default(): self
    {
        return new self(
            probabilityOfCareMale: 0.20,
            probabilityOfCareFemale: 0.30,
            meanDurationYears: 2.5,
            maxDurationYears: 8,
            probabilityNursing: 0.35,
            residentialWeekly: Money::fromPounds(1_300),
            nursingWeekly: Money::fromPounds(1_600),
        );
    }

    /**
     * The lifetime probability of needing residential/nursing care for a person of this sex.
     * Women's rate is materially higher (see the class sources); a person's care Bernoulli in
     * {@see CareCostSampler} draws against this.
     */
    public function probabilityOfCare(Sex $sex): float
    {
        return $sex === Sex::Female ? $this->probabilityOfCareFemale : $this->probabilityOfCareMale;
    }

    public function residentialAnnual(): Money
    {
        return Money::fromPence($this->residentialWeekly->pence * self::WEEKS_PER_YEAR);
    }

    /**
     * A year of nursing care, NET of {@see FUNDED_NURSING_CARE_WEEKLY_PENCE}. The NHS pays that
     * part of the fee direct to the home for anyone assessed as needing nursing, self-funder or
     * not, so it is never a bill the household meets (board card 0059). Floored at zero: a
     * contribution larger than the fee is not a refund.
     */
    public function nursingAnnual(): Money
    {
        return Money::fromPence(
            max(0, $this->nursingWeekly->pence - $this->fundedNursingCareWeekly()->pence) * self::WEEKS_PER_YEAR,
        );
    }
}

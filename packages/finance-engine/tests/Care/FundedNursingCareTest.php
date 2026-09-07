<?php

declare(strict_types=1);

namespace RetireForecast\FinanceEngine\Tests\Care;

use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Care\CareAssumptions;
use RetireForecast\FinanceEngine\Money\Money;

/**
 * NHS-funded Nursing Care (board card 0059, the estate planner's finding of 2026-08-19).
 *
 * FNC is paid by the NHS DIRECT to the nursing home for any resident assessed as needing care from
 * a registered nurse, INCLUDING a self-funder, and it comes off the fee the resident is charged.
 * The engine charged the whole gross nursing fee for every year of a nursing spell, which
 * overstates a nursing placement by the contribution every single week it runs.
 */
final class FundedNursingCareTest extends TestCase
{
    public function test_a_nursing_home_fee_is_charged_net_of_the_nhs_contribution(): void
    {
        $care = CareAssumptions::default();

        $gross = $care->nursingWeekly->pence;
        $fnc = $care->fundedNursingCareWeekly()->pence;

        $this->assertSame(CareAssumptions::FUNDED_NURSING_CARE_WEEKLY_PENCE, $fnc);
        $this->assertTrue($fnc > 0 && $fnc < $gross, 'the contribution is a real deduction, smaller than the fee');

        $this->assertSame(
            ($gross - $fnc) * CareAssumptions::WEEKS_PER_YEAR,
            $care->nursingAnnual()->pence,
            'the nursing fee charged must be net of the NHS contribution, every week of the spell',
        );
    }

    public function test_residential_care_gets_no_nursing_contribution(): void
    {
        $care = CareAssumptions::default();

        // FNC pays for NURSING. A residential placement has no registered nurse to fund, so the
        // fee stands whole — netting it off both would understate the care risk the tool exists
        // to show.
        $this->assertSame(
            $care->residentialWeekly->pence * CareAssumptions::WEEKS_PER_YEAR,
            $care->residentialAnnual()->pence,
        );
    }

    public function test_a_contribution_larger_than_the_fee_cannot_make_the_fee_negative(): void
    {
        $care = new CareAssumptions(
            probabilityOfCareMale: 0.2,
            probabilityOfCareFemale: 0.3,
            meanDurationYears: 2.5,
            maxDurationYears: 8,
            probabilityNursing: 0.35,
            residentialWeekly: Money::fromPounds(1_300),
            nursingWeekly: Money::fromPounds(100),
            fundedNursingCareWeekly: Money::fromPounds(250),
        );

        $this->assertSame(0, $care->nursingAnnual()->pence);
    }
}

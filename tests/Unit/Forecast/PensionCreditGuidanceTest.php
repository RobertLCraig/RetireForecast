<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
use RetireForecast\FinanceEngine\Forecast\YearResult;
use RetireForecast\FinanceEngine\Money\Money;
use RetireForecast\FinanceEngine\Support\Warning;
use RetireForecast\FinanceEngine\Support\WarningCode;

/**
 * Pension Credit is means-tested — it has to be claimed, and is heavily under-claimed — so
 * when the forecast credits it as income, the results page must say how to claim it. The
 * guidance appears only when some year actually receives it (nothing to claim otherwise),
 * and points at gov.uk, never states a guaranteed amount (only the DWP can confirm).
 */
final class PensionCreditGuidanceTest extends TestCase
{
    /** @param  list<Warning>  $warnings */
    private function year(Money $pensionCredit, array $warnings = []): YearResult
    {
        return new YearResult(
            yearIndex: 0,
            calendarYear: 2030,
            ages: ['p1' => 70],
            aliveCount: 1,
            grossIncome: Money::fromPounds(15_000),
            totalTax: Money::zero(),
            netIncome: Money::fromPounds(15_000),
            spendTarget: Money::fromPounds(15_000),
            essentialSpend: Money::fromPounds(15_000),
            shortfallFunded: Money::zero(),
            unmetSpend: Money::zero(),
            essentialsMet: true,
            liquidWealth: Money::zero(),
            pensionWealth: Money::zero(),
            propertyWealth: Money::zero(),
            incomeBySource: ['means_tested_benefit' => $pensionCredit],
            warnings: $warnings,
        );
    }

    private function forecast(YearResult ...$years): ForecastResult
    {
        return new ForecastResult(array_values($years), true, true, null, Money::zero(), Money::zero(), 2030);
    }

    public function test_guidance_appears_when_pension_credit_is_credited(): void
    {
        $guidance = ResultPresenter::pensionCreditGuidance($this->forecast($this->year(Money::fromPounds(1_750))));

        $this->assertNotNull($guidance);
        // It tells the reader how to claim (gov.uk + the claim line) and what it passports to.
        $this->assertNotEmpty($guidance['howToClaim']);
        $this->assertStringContainsString('gov.uk/pension-credit', implode(' ', $guidance['howToClaim']));
        $this->assertStringContainsString('0800 99 1234', implode(' ', $guidance['howToClaim']));
        $this->assertContains('Council Tax Reduction', $guidance['passports']);
        $this->assertSame('https://www.gov.uk/pension-credit', $guidance['source']);
    }

    public function test_guidance_appears_when_a_year_only_just_misses_the_pension_credit_line(): void
    {
        // Board card 0046. The prompt used to key off a positive award, so the household sitting
        // just above the line, the exact one a caseworker most wants a nil claim from, was shown
        // nothing at all. The engine flags that year as a near miss; the prompt reads the flag.
        $guidance = ResultPresenter::pensionCreditGuidance($this->forecast($this->year(
            Money::zero(),
            [new Warning(WarningCode::PENSION_CREDIT_NEAR_MISS, 'Assessable income is only just above the line.')],
        )));

        $this->assertNotNull($guidance);
        $this->assertFalse($guidance['awarded'], 'no year is awarded anything, so the prompt says so');
        // It states the backdating limit, which is what makes claiming now worth doing.
        $this->assertStringContainsString('3 months', implode(' ', $guidance['howToClaim']));
        // And it says why it appeared, quoting the engine rather than restating its rule.
        $this->assertStringContainsString('only just above the line', implode(' ', $guidance['nearMiss']));
    }

    public function test_a_mixed_age_couple_is_told_pension_credit_is_shut_and_what_replaces_it(): void
    {
        // Board card 0051. A nil award for a mixed-age couple is not a means test the household
        // failed, it is a door that is shut until the younger partner reaches State Pension age.
        // The panel must open on that too, quoting the engine's own explanation.
        $guidance = ResultPresenter::pensionCreditGuidance($this->forecast($this->year(
            Money::zero(),
            [new Warning(WarningCode::MIXED_AGE_COUPLE, 'One partner is under State Pension age, so Universal Credit applies instead.')],
        )));

        $this->assertNotNull($guidance, 'a silent nil is exactly the failure this card names');
        $this->assertFalse($guidance['awarded']);
        $this->assertStringContainsString('Universal Credit', implode(' ', $guidance['mixedAge']));
        // And it names the last year the trap applies, so the reader can see how long it lasts.
        $this->assertStringContainsString('2030', implode(' ', $guidance['mixedAge']));
    }

    public function test_a_mixed_age_couple_is_not_told_to_apply_for_pension_credit_and_is_told_what_to_check_instead(): void
    {
        // Board card 0051 #4, reopened on review. The panel told a mixed-age couple the door was
        // shut and then, in the same box, to apply online at gov.uk/pension-credit. The
        // replacement was named and never actioned.
        $guidance = ResultPresenter::pensionCreditGuidance($this->forecast($this->year(
            Money::zero(),
            [new Warning(WarningCode::MIXED_AGE_COUPLE, 'One partner is under State Pension age, so Universal Credit applies instead.')],
        )));

        $this->assertNotNull($guidance);
        $this->assertSame([], $guidance['howToClaim'], 'no claim steps for a benefit the household cannot claim');
        $this->assertSame([], $guidance['passports'], 'nor what an award it cannot get would passport');
        $this->assertStringContainsString('Universal Credit', implode(' ', $guidance['instead']));
        $this->assertStringContainsString('2031', implode(' ', $guidance['instead']), 'and it says when Pension Credit opens');
    }

    public function test_a_mixed_age_couple_awarded_pension_credit_later_is_told_when_to_claim(): void
    {
        // Mixed-age in 2030, both over State Pension age and awarded in 2031: the claim steps
        // apply, but only from the year the door opens.
        $later = new YearResult(
            yearIndex: 1, calendarYear: 2031, ages: ['p1' => 71], aliveCount: 1,
            grossIncome: Money::fromPounds(15_000), totalTax: Money::zero(), netIncome: Money::fromPounds(15_000),
            spendTarget: Money::fromPounds(15_000), essentialSpend: Money::fromPounds(15_000),
            shortfallFunded: Money::zero(), unmetSpend: Money::zero(), essentialsMet: true,
            liquidWealth: Money::zero(), pensionWealth: Money::zero(), propertyWealth: Money::zero(),
            incomeBySource: ['means_tested_benefit' => Money::fromPounds(1_000)],
        );
        $guidance = ResultPresenter::pensionCreditGuidance($this->forecast(
            $this->year(Money::zero(), [new Warning(WarningCode::MIXED_AGE_COUPLE, 'Universal Credit applies instead.')]),
            $later,
        ));

        $this->assertTrue($guidance['awarded']);
        $this->assertStringContainsString('gov.uk/pension-credit', implode(' ', $guidance['howToClaim']));
        $this->assertStringContainsString('2031', $guidance['howToClaim'][0], 'the first step says when the claim can start');
    }

    public function test_no_guidance_when_no_year_receives_pension_credit(): void
    {
        // A household above the means test gets £0 Pension Credit and is nowhere near the line —
        // there is nothing to claim, so the note must not appear (no noise, no implying an
        // entitlement that isn't modelled).
        $this->assertNull(ResultPresenter::pensionCreditGuidance($this->forecast($this->year(Money::zero()))));
    }
}

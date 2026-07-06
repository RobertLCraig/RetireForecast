<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;

/**
 * Input-sanity notes — the heads-up that explains a "wild numbers" result back to the input
 * that caused it (Rob's live-edit foot-guns: a retirement age at/below the current age that
 * zeroes salary, and a longevity setting below the current age that floors death to the base
 * year). The trust property: when an input does something drastic, the result says so rather
 * than collapsing silently; when nothing is amiss, there is no noise.
 */
final class InputNotesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $state
     * @return list<array{kind: string, text: string}>
     */
    private function notes(array $state): array
    {
        $household = (new HouseholdAssembler)->household($state);
        $forecast = (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));

        return ResultPresenter::inputNotes($household, $forecast);
    }

    public function test_a_spending_smile_is_surfaced_as_a_note_naming_the_reference_person(): void
    {
        // Born 1958 ⇒ age 68 in 2026; discretionary spend steps £8k → £3k from age 78 (a "smile").
        $notes = $this->notes([
            'householdName' => 'Smiler', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Robin', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [
                ['id' => 'e1', 'amount' => '15000', 'category' => 'essential'],
                ['id' => 'd1', 'amount' => '8000', 'category' => 'discretionary', 'bands' => [['fromAge' => 78, 'amount' => '3000']]],
            ],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $kinds = array_column($notes, 'kind');
        $this->assertContains('spending_smile', $kinds);
        $text = $notes[array_search('spending_smile', $kinds, true)]['text'];
        $this->assertStringContainsString('steps down', $text);
        $this->assertStringContainsString('age 78', $text);
        $this->assertStringContainsString('Robin', $text);
    }

    public function test_a_flat_plan_raises_no_spending_smile_note(): void
    {
        $notes = $this->notes([
            'householdName' => 'Flat', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Alex', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $this->assertNotContains('spending_smile', array_column($notes, 'kind'));
    }

    public function test_a_retirement_age_at_or_below_current_age_flags_no_salary(): void
    {
        // Born 1960 ⇒ age 66 in 2026; employed with a retirement age of 60 ⇒ no salary modelled.
        $notes = $this->notes([
            'householdName' => 'Past retire', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Pat', 'dob' => '1960-01-01', 'sex' => 'female', 'employmentStatus' => 'employed',
                    'grossSalary' => '40000', 'plannedRetirementAge' => '60'],
            ],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $kinds = array_column($notes, 'kind');
        $this->assertContains('no_salary', $kinds);
        $this->assertStringContainsString('No earnings are modelled for Pat', $notes[array_search('no_salary', $kinds, true)]['text']);
    }

    public function test_a_longevity_setting_below_current_age_flags_an_immediate_death(): void
    {
        // Born 1955 ⇒ age 71 in 2026; a fixed death age of 60 is floored to 71, so the person
        // is modelled to die in the base year. The partner keeps the projection running.
        $notes = $this->notes([
            'householdName' => 'Floored', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Sam', 'dob' => '1955-01-01', 'sex' => 'male', 'employmentStatus' => 'retired',
                    'longevityMode' => 'fixed_age', 'longevityValue' => '60'],
                ['id' => 'p2', 'name' => 'Lee', 'dob' => '1960-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $early = array_values(array_filter($notes, fn (array $n): bool => $n['kind'] === 'early_death'));
        $this->assertCount(1, $early);
        $this->assertStringContainsString('Sam is modelled to die in 2026', $early[0]['text']);
    }

    public function test_an_employed_person_with_no_retirement_age_is_flagged(): void
    {
        // Employed with a blank retirement age ⇒ modelled as earning for life (the V2 foot-gun).
        $notes = $this->notes([
            'householdName' => 'Forever', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Chris', 'dob' => '1960-01-01', 'sex' => 'female', 'employmentStatus' => 'employed',
                    'grossSalary' => '30000', 'plannedRetirementAge' => ''],
            ],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $kinds = array_column($notes, 'kind');
        $this->assertContains('no_retirement_age', $kinds);
        $this->assertStringContainsString('earning their salary indefinitely', $notes[array_search('no_retirement_age', $kinds, true)]['text']);
    }

    public function test_a_mortgage_due_for_redemption_is_flagged(): void
    {
        // The current home's mortgage is called for redemption within the plan — the forecast
        // must say so, not leave a "keep paying forever" path implied (the V2 forced-sale case).
        $notes = $this->notes([
            'householdName' => 'Redeem', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Pat', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'name' => 'Lee', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => [
                'currentValue' => '350000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '208000',
                'mortgageRedemptionYear' => '2026', 'mortgageMaturityAction' => 'forced_sale',
            ],
        ]);

        $flag = array_values(array_filter($notes, fn (array $n): bool => $n['kind'] === 'mortgage_redemption'));
        $this->assertCount(1, $flag);
        $this->assertStringContainsString('due for redemption in 2026', $flag[0]['text']);
        $this->assertStringContainsString('£208,000', $flag[0]['text']);
    }

    public function test_a_rolling_up_lifetime_mortgage_is_flagged_with_its_estate_effect(): void
    {
        // An equity-release lifetime mortgage with no payments: the note must state the rate and
        // what the compounding balance leaves behind, so the (gross) wealth line can't quietly
        // flatter a plan whose home equity the rolled-up interest has consumed.
        $notes = $this->notes([
            'householdName' => 'Roll-up', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Pat', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'name' => 'Lee', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => [
                'currentValue' => '350000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '118000',
                'mortgageRollUpRate' => '6.5',
            ],
        ]);

        $flag = array_values(array_filter($notes, fn (array $n): bool => $n['kind'] === 'lifetime_mortgage_rollup'));
        $this->assertCount(1, $flag);
        $this->assertStringContainsString('rolling up at 6.5% a year', $flag[0]['text']);
        $this->assertStringContainsString('left to inherit', $flag[0]['text']);
    }

    public function test_a_static_mortgage_raises_no_roll_up_note(): void
    {
        // No roll-up rate ⇒ a repayment/serviced mortgage ⇒ no roll-up note (no noise).
        $notes = $this->notes([
            'householdName' => 'Serviced', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Pat', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'name' => 'Lee', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => [
                'currentValue' => '350000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '118000',
            ],
        ]);

        $this->assertNotContains('lifetime_mortgage_rollup', array_column($notes, 'kind'));
    }

    public function test_a_cohabiting_couple_with_a_db_survivor_pension_is_flagged(): void
    {
        // A DB scheme's survivor pension usually goes to a spouse/civil partner, not a cohabitant,
        // and State Pension can't be inherited by a cohabiting partner — flag both so the survivor's
        // income is not silently overstated.
        $notes = $this->notes([
            'householdName' => 'Cohab', 'region' => 'england_wales_ni', 'relationshipStatus' => 'cohabiting',
            'people' => [
                ['id' => 'p1', 'name' => 'Ari', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'name' => 'Bo', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'db1', 'ownerId' => 'p2', 'subtype' => 'db', 'accruedAnnualPension' => '12000', 'normalRetirementAge' => '65', 'spousePensionFraction' => '50'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $kinds = array_column($notes, 'kind');
        $this->assertContains('cohabiting_db_survivor', $kinds);
        $this->assertContains('cohabiting_state_pension', $kinds);
    }

    public function test_a_married_couple_with_the_same_db_pension_raises_no_cohabiting_caveat(): void
    {
        $notes = $this->notes([
            'householdName' => 'Married', 'region' => 'england_wales_ni', 'relationshipStatus' => 'married_or_civil_partnership',
            'people' => [
                ['id' => 'p1', 'name' => 'Ari', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'name' => 'Bo', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'db1', 'ownerId' => 'p2', 'subtype' => 'db', 'accruedAnnualPension' => '12000', 'normalRetirementAge' => '65', 'spousePensionFraction' => '50'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $kinds = array_column($notes, 'kind');
        $this->assertNotContains('cohabiting_db_survivor', $kinds);
        $this->assertNotContains('cohabiting_state_pension', $kinds);
    }

    public function test_a_sensible_household_raises_no_notes(): void
    {
        // Employed retiring in the future, normal longevity ⇒ nothing to flag (no noise).
        $notes = $this->notes([
            'householdName' => 'Fine', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Alex', 'dob' => '1965-01-01', 'sex' => 'female', 'employmentStatus' => 'employed',
                    'grossSalary' => '40000', 'plannedRetirementAge' => '67'],
                ['id' => 'p2', 'name' => 'Jo', 'dob' => '1963-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $this->assertSame([], $notes);
    }
}

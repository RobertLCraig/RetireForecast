<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use App\Forecast\ResultPresenter;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Benefits\SupportForMortgageInterest;
use RetireForecast\FinanceEngine\Dto\Household;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastResult;
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

        return ResultPresenter::inputNotes($household, $forecast, (new HouseholdAssembler)->housingAction($state['housing'] ?? []));
    }

    /** The deterministic forecast for a household, for the notes that need the action passed too. */
    private function forecastFor(Household $household): ForecastResult
    {
        return (new DeterministicForecaster(
            TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi),
            new CohortLifeTable,
        ))->forecast($household, AssumptionSetLibrary::default(), new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27'));
    }

    public function test_the_capital_cliff_is_surfaced_as_a_note_naming_the_year_it_starts(): void
    {
        // Board card 0046. METHODOLOGY.md told the reader that losing Housing Benefit and Council
        // Tax Support above the capital limit IS flagged, while nothing in the app collected the
        // warning the engine built. Every sell-and-rent plan parks a large sum, so this reaches
        // most of the plans the tool exists to compare.
        $state = [
            'householdName' => 'Cliff', 'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1953-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            // £300/wk is above the single guarantee, so no Guarantee Credit is in payment and the
            // passport carve-out (a household on the credit keeps both) does not apply here.
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '300']],
            'accounts' => [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '180000']],
            'expenseLines' => [['id' => 'e', 'amount' => '20000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ];

        $notes = $this->notes($state);
        $kinds = array_column($notes, 'kind');
        $this->assertContains('capital_cliff', $kinds);

        $text = $notes[array_search('capital_cliff', $kinds, true)]['text'];
        $this->assertStringContainsString('2026', $text, 'the note names the year the cliff starts');
        $this->assertStringContainsString('Housing Benefit', $text);

        // The same household under the limit gets no note: a disclosure that always fires is noise.
        // Spending is raised above their income too, so the savings are drawn down rather than
        // added to: a household that banks a surplus every year crosses the limit on its own.
        $state['accounts'] = [['id' => 'a1', 'ownerId' => 'p1', 'type' => 'cash', 'balance' => '9000']];
        $state['expenseLines'] = [['id' => 'e', 'amount' => '30000', 'category' => 'essential']];
        $this->assertNotContains('capital_cliff', array_column($this->notes($state), 'kind'));
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

    public function test_above_cpi_property_cost_growth_is_surfaced_as_a_note(): void
    {
        // A service charge with an above-inflation growth rate must be visible on the results
        // page — the later-year squeeze reads as intended, not as a bug (no silent modelling).
        $notes = $this->notes([
            'householdName' => 'Leaseholder', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Alex', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [
                ['id' => 'e1', 'amount' => '15000', 'category' => 'essential'],
                ['id' => 'sc', 'label' => 'Service Charge', 'amount' => '6000', 'category' => 'essential'],
            ],
            'expense' => ['survivorFactor' => '70', 'propertyCostsGrowthPct' => '1.5'],
        ]);

        $kinds = array_column($notes, 'kind');
        $this->assertContains('property_costs_growth', $kinds);
        $text = $notes[array_search('property_costs_growth', $kinds, true)]['text'];
        $this->assertStringContainsString('1.5% a year above inflation', $text);
        $this->assertStringContainsString('£6,000.00', $text);
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

    /**
     * @param  array<string, mixed>  $property
     * @return list<array{kind: string, text: string}>
     */
    private function landlordNotes(array $property): array
    {
        return $this->notes([
            'householdName' => 'Landlord', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Alex', 'dob' => '1958-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'incomeStreams' => [['id' => 'i1', 'ownerId' => 'p1', 'type' => 'rental', 'grossAnnual' => '18000',
                'taxable' => true, 'inflationLinked' => true, 'startAge' => 0]],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '350000', 'ownership' => 'outright'] + $property,
        ]);
    }

    public function test_a_let_plan_shows_the_letting_caveats_on_the_result(): void
    {
        // Card 0030. The caveats on letting a home used to live in a docblock, where nobody reading
        // the forecast could see them: the deductions taken, the energy-efficiency retrofit that is
        // not modelled, and the freeholder's consent a lease usually needs before you sublet at all.
        $notes = $this->landlordNotes(['isLet' => true]);

        $kinds = array_column($notes, 'kind');
        $this->assertContains('letting_caveats', $kinds);
        $text = $notes[array_search('letting_caveats', $kinds, true)]['text'];

        $this->assertStringContainsString('EPC', $text, 'the minimum energy efficiency standard is unmodelled and must be said');
        $this->assertStringContainsString('freeholder', $text, 'a lease usually needs consent to sublet, and may forbid it');
        $this->assertStringContainsString('council tax', $text, 'the model still charges it although a tenant normally pays it');
    }

    public function test_a_home_they_live_in_raises_no_letting_note(): void
    {
        // No noise: none of it applies to a household living in their own home.
        $this->assertNotContains('letting_caveats', array_column($this->landlordNotes([]), 'kind'));
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

    public function test_support_for_mortgage_interest_is_flagged_with_its_rate_cap_and_charge(): void
    {
        // A poor pensioner couple on Guarantee Credit with a mortgaged leasehold flat: the model
        // hands them Support for Mortgage Interest, which nobody entered and which both lowers
        // their spending and eats their estate. The rate and the cap are the engine's, so the note
        // has to state both, and it has to say the help is a loan against the home.
        $notes = $this->notes([
            'householdName' => 'SMI', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [
                ['id' => 'p1', 'name' => 'Pat', 'dob' => '1950-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'name' => 'Lee', 'dob' => '1950-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '90'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '90'],
            ],
            'expenseLines' => [
                ['id' => 'e1', 'label' => 'Living costs', 'amount' => '18000', 'category' => 'essential'],
                ['id' => 'e2', 'label' => 'Mortgage', 'amount' => '9000', 'category' => 'essential'],
                ['id' => 'e3', 'label' => 'Service charge', 'amount' => '2400', 'category' => 'essential'],
            ],
            'expense' => ['survivorFactor' => '70', 'propertyCostsGrowthPct' => '0'],
            'hasProperty' => true,
            'property' => ['currentValue' => '350000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '150000'],
        ]);

        $flag = array_values(array_filter($notes, fn (array $n): bool => $n['kind'] === 'support_for_mortgage_interest'));
        $this->assertCount(1, $flag);
        $this->assertStringContainsString(
            SupportForMortgageInterest::eligibleCapitalLimit()->format(),
            $flag[0]['text'],
            'the cap the interest is met up to has to be on the screen',
        );
        $this->assertStringContainsString('2.09% a year', $flag[0]['text'], 'and the standard rate it is met at');
        $this->assertStringContainsString('It is a LOAN', $flag[0]['text']);
        $this->assertStringContainsString('repaid when the home is sold', $flag[0]['text']);
    }

    public function test_a_household_that_never_reaches_guarantee_credit_is_told_nothing_about_smi(): void
    {
        // The same flat and the same mortgage, but two full State Pensions: no Guarantee Credit, so
        // no Support for Mortgage Interest is modelled and none is claimed on the screen. Telling a
        // household about help the projection never gave it is the mirror-image invisible figure.
        $notes = $this->notes([
            'householdName' => 'No SMI', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [
                ['id' => 'p1', 'name' => 'Pat', 'dob' => '1950-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'name' => 'Lee', 'dob' => '1950-01-01', 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '300'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '300'],
            ],
            'expenseLines' => [
                ['id' => 'e1', 'label' => 'Living costs', 'amount' => '18000', 'category' => 'essential'],
                ['id' => 'e2', 'label' => 'Mortgage', 'amount' => '9000', 'category' => 'essential'],
                ['id' => 'e3', 'label' => 'Service charge', 'amount' => '2400', 'category' => 'essential'],
            ],
            'expense' => ['survivorFactor' => '70', 'propertyCostsGrowthPct' => '0'],
            'hasProperty' => true,
            'property' => ['currentValue' => '350000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '150000'],
        ]);

        $this->assertNotContains('support_for_mortgage_interest', array_column($notes, 'kind'));
    }

    public function test_a_repayment_mortgage_is_flagged_with_its_instalment_and_clearing_year(): void
    {
        // The ESIS quote: £160,000 over 16 years, 6.23% fixed for 60 months then 7.24%. The note
        // must state the instalment, the step when the deal reverts, and the year it clears — and
        // must call out the two properties a reader gets wrong (fixed in cash terms; unchanged for
        // a survivor), because that is where a later-life repayment mortgage becomes unaffordable.
        $notes = $this->notes([
            'householdName' => 'Repayment', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
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
                'currentValue' => '400000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '160000',
                'mortgageRepaymentTermMonths' => '192', 'mortgageRepaymentStartYear' => '2026',
                'mortgageRepaymentStartMonth' => '9', 'mortgageRepaymentRate' => '6.23',
                'mortgageRepaymentInitialMonths' => '60', 'mortgageRepaymentRevertRate' => '7.24',
            ],
        ]);

        $flag = array_values(array_filter($notes, fn (array $n): bool => $n['kind'] === 'repayment_mortgage'));
        $this->assertCount(1, $flag);

        // The instalments are the lender's own, to the penny, and the step is named.
        $this->assertStringContainsString('£1,318.54 a month', $flag[0]['text']);
        $this->assertStringContainsString('stepping to £1,384.65', $flag[0]['text']);
        $this->assertStringContainsString('clears in 2042', $flag[0]['text']);
        $this->assertStringContainsString('over 16 years', $flag[0]['text']);
        $this->assertStringContainsString('does NOT fall if one of you dies', $flag[0]['text']);
    }

    public function test_a_home_that_loses_value_is_flagged_with_what_it_leaves_behind(): void
    {
        // A reader's whole mental model of a home is that it appreciates. A park home does not, and
        // the wealth line quietly falling must be explained, not left to look like a bug.
        $state = [
            'householdName' => 'Park home', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'name' => 'Pat', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '350000', 'ownership' => 'outright'],
            'housing' => ['salePrice' => '350000', 'buyPrice' => '150000', 'buyGrowthReal' => '-8'],
        ];

        $household = (new HouseholdAssembler)->household($state);
        $forecast = $this->forecastFor($household);
        $action = (new HouseholdAssembler)->housingAction($state['housing']);

        $notes = ResultPresenter::inputNotes($household, $forecast, $action);
        $flag = array_values(array_filter($notes, fn (array $n): bool => $n['kind'] === 'home_depreciates'));

        $this->assertCount(1, $flag);
        $this->assertStringContainsString('LOSING value', $flag[0]['text']);
        $this->assertStringContainsString('8% a year', $flag[0]['text']);
        $this->assertStringContainsString('10% of the sale price', $flag[0]['text']);
        $this->assertStringContainsString('less is left to inherit', $flag[0]['text']);
    }

    public function test_an_ordinary_purchase_raises_no_depreciation_note(): void
    {
        $state = [
            'householdName' => 'Ordinary', 'region' => 'england_wales_ni', 'baseTaxYear' => '2026-27',
            'people' => [['id' => 'p1', 'name' => 'Pat', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired']],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '350000', 'ownership' => 'outright'],
            'housing' => ['salePrice' => '350000', 'buyPrice' => '150000'],
        ];

        $household = (new HouseholdAssembler)->household($state);
        $notes = ResultPresenter::inputNotes(
            $household,
            $this->forecastFor($household),
            (new HouseholdAssembler)->housingAction($state['housing']),
        );

        $this->assertSame([], array_values(array_filter($notes, fn (array $n): bool => $n['kind'] === 'home_depreciates')));
    }

    public function test_a_static_mortgage_raises_no_repayment_note(): void
    {
        // No term ⇒ an interest-only / RIO shape ⇒ no amortisation note (no noise).
        $notes = $this->notes([
            'householdName' => 'Serviced', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Pat', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => [
                'currentValue' => '350000', 'ownership' => 'mortgaged', 'outstandingMortgage' => '118000',
            ],
        ]);

        $this->assertSame([], array_values(array_filter($notes, fn (array $n): bool => $n['kind'] === 'repayment_mortgage')));
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

    public function test_a_disability_benefit_note_names_the_start_age_and_everything_it_passports(): void
    {
        // Card 0044. The forecast models ONE consequence of a disability benefit, the Pension Credit
        // addition. The benefit also passports a stack of help that is worth more per year than the
        // survivor shortfall this tool exists to close, and none of that is in the figures, so the
        // result has to say so rather than let the reader assume it is all counted.
        $notes = $this->notes([
            'householdName' => 'Claimant', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Robin', 'dob' => '1958-01-01', 'sex' => 'female', 'employmentStatus' => 'retired',
                    'receivesDisabilityBenefit' => true, 'disabilityBenefitFromAge' => '80'],
            ],
            'pensions' => [['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230']],
            'expenseLines' => [['id' => 'e1', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $kinds = array_column($notes, 'kind');
        $this->assertContains('disability_benefit_passports', $kinds);
        $text = $notes[array_search('disability_benefit_passports', $kinds, true)]['text'];

        $this->assertStringContainsString('Robin', $text);
        $this->assertStringContainsString('age 80', $text);
        $this->assertStringContainsString('Pension Credit', $text);
        foreach (['Council Tax', 'Warm Home Discount', 'TV licence', 'Cold Weather', 'NHS', 'Support for Mortgage Interest'] as $passport) {
            $this->assertStringContainsString($passport, $text);
        }
    }

    /**
     * A retired couple in a home they own, whose council tax is either split out or left inside
     * the running costs. £300 a week each is above the couple guarantee, so no Council Tax
     * Reduction confuses the figures being read.
     *
     * @return array<string, mixed>
     */
    private function councilTaxHousehold(string $councilTax, string $runningCosts, string $band = ''): array
    {
        return [
            'householdName' => 'Council tax', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'dob' => '1953-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'dob' => '1953-01-01', 'sex' => 'male', 'employmentStatus' => 'retired',
                    'longevityMode' => 'fixed_age', 'longevityValue' => '80'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '300'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '300'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '25000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => [
                'currentValue' => '300000', 'ownership' => 'outright',
                'runningCosts' => $runningCosts, 'councilTax' => $councilTax, 'councilTaxDisabledBand' => $band,
            ],
        ];
    }

    public function test_the_council_tax_note_states_what_is_charged_and_what_reduces_it(): void
    {
        // Board card 0047, and the no-invisible-figures rule: a bill the model is quietly
        // discounting has to say so, with the figure, or the reader cannot check it.
        $notes = $this->notes($this->councilTaxHousehold(councilTax: '2000', runningCosts: '1200', band: 'd'));
        $kinds = array_column($notes, 'kind');
        $this->assertContains('council_tax', $kinds);
        $this->assertNotContains('council_tax_bundled', $kinds);

        $text = $notes[array_search('council_tax', $kinds, true)]['text'];
        $this->assertStringContainsString('£2,000.00', $text, 'the note names the bill that was entered');
        $this->assertStringContainsString('band D', $text, 'and the band the disabled reduction is claimed from');
        // The second member dies at 80 (during 2033), so the first full year of a single-person
        // household is 2034: the note names that year and what the survivor then pays.
        $this->assertStringContainsString('2034', $text);
        $this->assertStringContainsString('£1,333.33', $text, 'the band D reduction and the discount both land');
        $this->assertStringContainsString('25%', $text);
    }

    public function test_council_tax_left_inside_the_running_costs_is_flagged_as_charged_in_full(): void
    {
        // The state every scenario stored before card 0047 is in. Nothing is wrong with the
        // arithmetic, but three reductions cannot reach a bundled figure, and a reader comparing
        // plans off it has no way to know that from the screen.
        $notes = $this->notes($this->councilTaxHousehold(councilTax: '', runningCosts: '3200'));
        $kinds = array_column($notes, 'kind');
        $this->assertContains('council_tax_bundled', $kinds);
        $this->assertNotContains('council_tax', $kinds);

        $text = $notes[array_search('council_tax_bundled', $kinds, true)]['text'];
        $this->assertStringContainsString('£3,200.00', $text);
        $this->assertStringContainsString('in full', $text);
    }

    /**
     * The notes a SELL-AND-RENT plan raises. The rent lives on the housing action, not on the
     * household, and the forecast handed to the presenter is deliberately the stay-put one, so
     * these notes are driven by the action and the variant rather than by a rent figure in the
     * years. That is why they are built through their own helper.
     *
     * @param  string  $secondDob  the second member's date of birth: 1955 is well past State
     *                             Pension age in 2026, 1975 is well short of it
     * @return list<array{kind: string, text: string}>
     */
    private function rentPlanNotes(string $secondDob): array
    {
        $state = [
            'householdName' => 'Renter', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'name' => 'Alex', 'dob' => '1953-01-01', 'sex' => 'female', 'employmentStatus' => 'retired'],
                ['id' => 'p2', 'name' => 'Jo', 'dob' => $secondDob, 'sex' => 'male', 'employmentStatus' => 'retired'],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '180'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '180'],
            ],
            'expenseLines' => [['id' => 'e1', 'amount' => '20000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
            'hasProperty' => true,
            'property' => ['currentValue' => '300000', 'ownership' => 'outright'],
            'housing' => ['salePrice' => '300000', 'annualRent' => '15000'],
        ];

        $household = (new HouseholdAssembler)->household($state);

        return ResultPresenter::inputNotes(
            $household,
            $this->forecastFor($household),
            (new HouseholdAssembler)->housingAction($state['housing']),
            'rent',
        );
    }

    public function test_a_rent_plan_states_what_housing_benefit_does_for_it(): void
    {
        // Board card 0048, and the no-invisible-figures rule: the rent line is now net of an
        // award the reader was never told about, so the plan has to say the help is in there and
        // on what terms. Both members are well past State Pension age, so nothing is excluded.
        $notes = $this->rentPlanNotes('1955-01-01');
        $kinds = array_column($notes, 'kind');
        $this->assertContains('housing_benefit', $kinds);
        $this->assertNotContains('housing_benefit_excluded', $kinds);

        $text = $notes[array_search('housing_benefit', $kinds, true)]['text'];
        $this->assertStringContainsString('65%', $text, 'the note names the taper the award is withdrawn at');
        $this->assertStringContainsString('£15,000.00', $text, 'and the rent it is set against');
        $this->assertStringContainsString('Local Housing Allowance', $text, 'and the cap that is NOT modelled');
    }

    public function test_a_rent_plan_with_a_member_under_state_pension_age_says_it_is_understated(): void
    {
        // The criterion the card wrote for the case it could not model: working-age Housing
        // Benefit is closed to new claims and its replacement is the Universal Credit housing
        // element, which the card put out of scope. Those years are therefore charged the whole
        // rent, and the plan has to say the shortfall is the model's and not the household's.
        $notes = $this->rentPlanNotes('1975-01-01');
        $kinds = array_column($notes, 'kind');
        $this->assertContains('housing_benefit_excluded', $kinds);

        $text = $notes[array_search('housing_benefit_excluded', $kinds, true)]['text'];
        $this->assertStringContainsString('2042', $text, 'the note names the year the exclusion ends');
        $this->assertStringContainsString('UNDERSTATED', $text);
    }

    public function test_a_sensible_household_raises_no_notes(): void
    {
        // Employed retiring in the future, normal longevity ⇒ nothing to flag (no noise).
        // Spending is set close to the household's income deliberately: this fixture used to bank
        // most of a £40,000 salary and pass £16,000 of savings by 2027, which the capital-cliff
        // note added by board card 0046 correctly reports. A household with something to flag is
        // the wrong fixture for a test about a household with nothing to flag.
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
            'expenseLines' => [['id' => 'e1', 'amount' => '38000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ]);

        $this->assertSame([], $notes);
    }
}

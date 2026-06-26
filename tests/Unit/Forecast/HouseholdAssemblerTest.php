<?php

declare(strict_types=1);

namespace Tests\Unit\Forecast;

use App\Forecast\HouseholdAssembler;
use PHPUnit\Framework\TestCase;
use RetireForecast\FinanceEngine\Assumptions\AssumptionSetLibrary;
use RetireForecast\FinanceEngine\Dto\LongevityAdjustment;
use RetireForecast\FinanceEngine\Forecast\DeterministicForecaster;
use RetireForecast\FinanceEngine\Forecast\ForecastSettings;
use RetireForecast\FinanceEngine\Mortality\CohortLifeTable;
use RetireForecast\FinanceEngine\TaxYear\RegionProfile;
use RetireForecast\FinanceEngine\TaxYear\TaxYearRegistry;
use Tests\Support\BuilderStateFixture;
use Tests\Support\HouseholdFixture;

/**
 * The builder is the third consumer of the one canonical shape. This proves the
 * assembly is lossless: form-shaped strings covering every nested DTO and optional
 * field rebuild exactly the rich {@see HouseholdFixture} household and housing
 * action, pounds parsed to exact pence with no float drift.
 */
class HouseholdAssemblerTest extends TestCase
{
    public function test_it_rebuilds_the_full_household_dto_from_form_state(): void
    {
        $assembled = (new HouseholdAssembler)->assemble(BuilderStateFixture::full());

        $this->assertEquals(HouseholdFixture::household(), $assembled['household']);
        $this->assertEquals(HouseholdFixture::housingAction(), $assembled['housingAction']);
    }

    public function test_pounds_and_pence_parse_to_exact_pence(): void
    {
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expense' => ['essential' => '28000.50', 'discretionary' => '', 'survivorFactor' => ''],
            'pensions' => [['ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230.25']],
        ]);

        $this->assertSame(2_800_050, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(23_025, $household->pensions[0]->weeklyForecast->pence);
    }

    public function test_expense_line_items_derive_the_essential_and_discretionary_totals(): void
    {
        // Essential = sum of essential lines; discretionary = discretionary lines plus
        // *spent* self-investment (consumption that does not build an asset).
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'l1', 'label' => 'Bills', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'l2', 'label' => 'Food', 'amount' => '5000', 'category' => 'essential'],
                ['id' => 'l3', 'label' => 'Holidays', 'amount' => '8000', 'category' => 'discretionary'],
                ['id' => 'l4', 'label' => 'A course', 'amount' => '2000', 'category' => 'self_investment', 'savedAsAsset' => false],
            ],
        ]);

        $this->assertSame(2_500_000, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(1_000_000, $household->expenseProfile->discretionaryAnnualSpend->pence);
        $this->assertSame([], $household->accounts); // nothing saved, so no synthetic account
    }

    public function test_saved_self_investment_becomes_a_contributing_account_not_spend(): void
    {
        // A *saved* self-investment line builds net worth: it is not counted as spend,
        // and appears once as a contributing (balance-zero) account — one home per pound.
        $household = (new HouseholdAssembler)->household([
            'householdName' => 'X',
            'region' => 'england_wales_ni',
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired']],
            'expenseLines' => [
                ['id' => 'l1', 'label' => 'Bills', 'amount' => '20000', 'category' => 'essential'],
                ['id' => 'l2', 'label' => 'Savings plan', 'amount' => '3000', 'category' => 'self_investment', 'savedAsAsset' => true],
            ],
        ]);

        $this->assertSame(2_000_000, $household->expenseProfile->essentialAnnualSpend->pence);
        $this->assertSame(0, $household->expenseProfile->discretionaryAnnualSpend->pence);

        $this->assertCount(1, $household->accounts);
        $saved = $household->accounts[0];
        $this->assertSame(0, $saved->balance->pence);
        $this->assertSame(300_000, $saved->ongoingContributions->pence);
    }

    public function test_the_lifespan_what_if_maps_to_the_engine_longevity_adjustment(): void
    {
        $assembler = new HouseholdAssembler;
        $person = fn (string $mode, string $value): array => [
            'householdName' => 'X', 'region' => 'england_wales_ni',
            'expenseLines' => [['id' => 'l1', 'label' => 'Bills', 'amount' => '20000', 'category' => 'essential']],
            'people' => [['id' => 'p1', 'dob' => '1960-01-01', 'sex' => 'male', 'employmentStatus' => 'retired',
                'longevityMode' => $mode, 'longevityValue' => $value]],
        ];

        // peer (or a blank value) leaves the cohort-table average in place.
        $this->assertNull($assembler->household($person('peer', ''))->persons[0]->longevity);
        $this->assertNull($assembler->household($person('fixed_age', ''))->persons[0]->longevity);
        // fixed age and ± year offset map to the matching adjustment.
        $this->assertEquals(LongevityAdjustment::fixedAge(82), $assembler->household($person('fixed_age', '82'))->persons[0]->longevity);
        $this->assertEquals(LongevityAdjustment::offsetYears(-5), $assembler->household($person('offset_years', '-5'))->persons[0]->longevity);
    }

    public function test_a_fixed_age_lifespan_what_if_reaches_the_forecast_and_shortens_it(): void
    {
        // Completeness: the form-level lifespan lever must actually move the result. A couple
        // (both 68 in 2026) assumed to die at 80 ends the forecast in 2038 — strictly earlier
        // than the same couple left on the cohort-table average.
        $state = fn (string $mode, string $value): array => [
            'householdName' => 'Lifespan', 'region' => 'england_wales_ni',
            'people' => [
                ['id' => 'p1', 'dob' => '1958-04-01', 'sex' => 'female', 'employmentStatus' => 'retired', 'longevityMode' => $mode, 'longevityValue' => $value],
                ['id' => 'p2', 'dob' => '1958-09-01', 'sex' => 'male', 'employmentStatus' => 'retired', 'longevityMode' => $mode, 'longevityValue' => $value],
            ],
            'pensions' => [
                ['id' => 'sp1', 'ownerId' => 'p1', 'subtype' => 'state', 'weeklyForecast' => '230'],
                ['id' => 'sp2', 'ownerId' => 'p2', 'subtype' => 'state', 'weeklyForecast' => '230'],
            ],
            'expenseLines' => [['id' => 'e', 'label' => 'Essentials', 'amount' => '15000', 'category' => 'essential']],
            'expense' => ['survivorFactor' => '70'],
        ];

        $assembler = new HouseholdAssembler;
        $forecaster = new DeterministicForecaster(TaxYearRegistry::for('2026-27', RegionProfile::EnglandWalesNi), new CohortLifeTable);
        $settings = new ForecastSettings(baseYear: 2026, baseTaxYear: '2026-27');
        $assumptions = AssumptionSetLibrary::default();

        $fixed = $forecaster->forecast($assembler->household($state('fixed_age', '80')), $assumptions, $settings);
        $peer = $forecaster->forecast($assembler->household($state('peer', '')), $assumptions, $settings);

        $this->assertSame(2038, $fixed->finalCalendarYear);
        $this->assertGreaterThan($fixed->finalCalendarYear, $peer->finalCalendarYear);
    }
}
